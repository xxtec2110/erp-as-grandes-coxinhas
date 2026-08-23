<?php

namespace Tests\Feature;

use App\Jobs\SyncPdvSalesJob;
use App\Models\Acquirer;
use App\Models\Location;
use App\Models\PdvConnection;
use App\Models\PdvIntegrationEvent;
use App\Models\PdvOrder;
use App\Models\PdvOrderPayment;
use App\Models\PdvPaymentMethodMapping;
use App\Models\Permission;
use App\Models\ProductSaleOrder;
use App\Models\User;
use App\Pdv\Data\ExternalSaleData;
use App\Pdv\Data\ExternalSaleItemData;
use App\Pdv\Data\ExternalSalePaymentData;
use App\Services\PaymentFeeService;
use App\Services\PdvConnectionService;
use App\Services\PdvOrderReconciliationService;
use App\Services\PdvOrderStagingService;
use App\Services\PdvSalesReconciliationService;
use App\Services\PdvSyncService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class GrandChefFinalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Location $location;

    private PdvConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create(['is_super_admin' => true, 'all_locations_access' => true]);
        $this->location = Location::query()->create(['name' => 'Loja teste final', 'type' => Location::TYPE_STORE, 'active' => true]);
        $this->connection = PdvConnection::query()->firstOrFail();
        $this->connection->update([
            'location_id' => $this->location->id,
            'name' => 'GrandChef teste final',
            'provider' => 'grandchef',
            'enabled' => true,
            'operational_start_at' => '2026-08-20 00:00:00-03:00',
            'configuration' => ['endpoint' => 'https://fixture.invalid/graphql'],
            'encrypted_credentials' => ['bearer_token' => 'fixture-bearer', 'device_token' => 'fixture-device'],
        ]);
    }

    public function test_card_payment_without_external_brand_can_be_configured_with_a_real_acquirer_and_fee(): void
    {
        $order = $this->order('CARD-ORDER', '2026-08-20 10:00:00-03:00', '10.00');
        PdvOrderPayment::query()->create([
            'pdv_order_id' => $order->id,
            'external_payment_id' => 'PAY-CREDIT',
            'external_form_id' => 'CREDIT',
            'external_form_description' => 'Crédito',
            'external_type' => 'credito',
            'amount' => '10.00',
            'external_total' => '10.00',
            'installments' => 1,
            'external_status' => 'pago',
            'present_in_latest' => true,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $acquirer = Acquirer::query()->create(['name' => 'Adquirente fictícia', 'active' => true]);
        $fee = app(PaymentFeeService::class)->apply([
            'acquirer_id' => $acquirer->id,
            'card_brand_id' => null,
            'payment_method' => 'credit',
            'installments' => null,
            'fee_percentage' => '2.500000',
            'fixed_fee' => '0.3000',
            'effective_from' => '2026-08-01',
            'notes' => 'Fixture sem bandeira externa.',
        ], $this->admin, 'test');

        $this->actingAs($this->admin)->put(route('pdv.mappings.payments.update', [$this->connection, 'CREDIT']), [
            'payment_method' => 'credit',
            'financial_configuration' => $acquirer->id.':none',
            'idempotency_key' => (string) Str::uuid(),
            'from' => '2026-08-20',
            'to' => '2026-08-20',
        ])->assertRedirect();

        $mapping = PdvPaymentMethodMapping::query()->where('external_method_code', 'CREDIT')->firstOrFail();
        $this->assertSame($acquirer->id, $mapping->acquirer_id);
        $this->assertNull($mapping->card_brand_id);
        $this->assertNull($fee->card_brand_id);
        $paymentStatus = app(PdvOrderReconciliationService::class)->reconcile($order->fresh('payments'))['payment_mapping_status'];
        $this->assertTrue($paymentStatus['ready']);
        $this->assertSame($fee->id, $paymentStatus['payments'][0]['fee']->id);
        $this->actingAs($this->admin)->get(route('payment-fees.index'))->assertOk()->assertSee('Sem bandeira externa');
    }

    public function test_connection_changes_are_audited_without_credential_values_and_blank_fields_preserve_credentials(): void
    {
        $service = app(PdvConnectionService::class);
        $service->update($this->connection, [
            'location_id' => $this->location->id,
            'name' => 'GrandChef auditado',
            'enabled' => true,
            'endpoint' => 'https://fixture.invalid/graphql',
            'bearer_token' => '',
            'device_token' => 'replacement-device-fixture',
        ], $this->admin);

        $fresh = $this->connection->fresh();
        $this->assertSame('fixture-bearer', $fresh->encrypted_credentials['bearer_token']);
        $this->assertSame('replacement-device-fixture', $fresh->encrypted_credentials['device_token']);
        $event = PdvIntegrationEvent::query()->where('event_type', 'connection_updated')->firstOrFail();
        $encoded = json_encode($event->metadata);
        $this->assertStringNotContainsString('fixture-bearer', $encoded);
        $this->assertStringNotContainsString('replacement-device-fixture', $encoded);
        $this->assertFalse($event->metadata['bearer_replaced']);
        $this->assertTrue($event->metadata['device_replaced']);
        $this->assertSame('fixture.invalid', $event->metadata['after']['endpoint_host']);
    }

    public function test_scheduler_continues_with_the_next_connection_after_an_isolated_failure(): void
    {
        $otherLocation = Location::query()->create(['name' => 'Segunda loja', 'type' => Location::TYPE_STORE, 'active' => true]);
        $otherConnection = PdvConnection::query()->create(['location_id' => $otherLocation->id, 'provider' => 'grandchef', 'name' => 'Segunda conexão', 'enabled' => true, 'status' => 'configured']);
        config()->set('pdv.enabled', true);
        config()->set('pdv.sync_enabled', true);
        $calls = [];
        $sync = Mockery::mock(PdvSyncService::class);
        $sync->shouldReceive('sync')->twice()->andReturnUsing(function (PdvConnection $connection) use (&$calls): array {
            $calls[] = $connection->id;
            if (count($calls) === 1) {
                throw new DomainException('Falha isolada de fixture.');
            }

            return ['staged' => 0, 'imported' => 0];
        });

        (new SyncPdvSalesJob)->handle($sync);

        $this->assertSame([$this->connection->id, $otherConnection->id], $calls);
    }

    public function test_period_reconciliation_matches_ten_orders_and_has_zero_side_effects(): void
    {
        for ($index = 1; $index <= 6; $index++) {
            $order = $this->order("IMPORTED-{$index}", '2026-08-20 10:00:00-03:00', '10.00', PdvOrder::STATE_IMPORTED);
            ProductSaleOrder::query()->create([
                'location_id' => $this->location->id,
                'pdv_connection_id' => $this->connection->id,
                'pdv_order_id' => $order->id,
                'operation_date' => '2026-08-20',
                'entry_source' => 'pdv',
                'external_reference' => $order->external_order_id,
                'status' => ProductSaleOrder::STATUS_COMPLETED,
                'subtotal_snapshot' => '10.00',
                'discount_total_snapshot' => '0.00',
                'service_total_snapshot' => '0.00',
                'delivery_total_snapshot' => '0.00',
                'total_amount_snapshot' => '10.00',
                'paid_total_snapshot' => '10.00',
                'change_total_snapshot' => '0.00',
                'imported_at' => now(),
                'idempotency_key' => "reconciliation-{$index}",
            ]);
        }
        $this->order('PRE-1', '2026-08-19 10:00:00-03:00', '10.00');
        $this->order('PRE-2', '2026-08-19 11:00:00-03:00', '10.00');
        $this->order('BLOCKED-1', '2026-08-20 12:00:00-03:00', '10.00');
        $this->order('CANCELLED-1', '2026-08-20 13:00:00-03:00', '10.00', PdvOrder::STATE_STAGED, 'cancelled');
        $before = $this->operationalCounts();

        $result = app(PdvSalesReconciliationService::class)->period($this->connection, CarbonImmutable::parse('2026-08-19'), CarbonImmutable::parse('2026-08-20'));

        $this->assertSame(10, $result['summary']['external_orders']);
        $this->assertSame(6, $result['summary']['imported']);
        $this->assertSame(2, $result['summary']['pre_operational']);
        $this->assertSame(1, $result['summary']['blocked']);
        $this->assertSame(1, $result['summary']['cancelled']);
        $this->assertSame('100.00', $result['summary']['external_total']);
        $this->assertSame('70.00', $result['summary']['comparable_external_total']);
        $this->assertSame('60.00', $result['summary']['official_total']);
        $this->assertSame('10.00', $result['summary']['difference']);
        $this->assertSame($before, $this->operationalCounts());
        $this->actingAs($this->admin)->get(route('pdv.reconciliation', [$this->connection, 'from' => '2026-08-19', 'to' => '2026-08-20']))->assertOk()->assertSee('Conciliação GrandChef × ERP')->assertSee('R$ 10,00');

        $otherLocation = Location::query()->create(['name' => 'Sem acesso', 'type' => Location::TYPE_STORE, 'active' => true]);
        $restricted = User::factory()->unprivileged()->create();
        $restricted->permissions()->attach(Permission::query()->where('name', 'pdv.manage')->firstOrFail(), ['allowed' => true]);
        $restricted->locations()->attach($otherLocation);
        $this->actingAs($restricted)->get(route('pdv.reconciliation', $this->connection))->assertForbidden();
    }

    public function test_grandchef_staging_only_records_external_sales_and_never_creates_erp_master_or_stock_data(): void
    {
        $before = $this->operationalCounts();
        $sale = new ExternalSaleData(
            provider: 'grandchef',
            externalSaleId: 'BOUNDARY-1',
            externalOrderNumber: 'BOUNDARY-1',
            externalLocationId: (string) $this->location->id,
            status: 'concluido',
            openedAt: CarbonImmutable::parse('2026-08-20 10:00:00-03:00'),
            closedAt: CarbonImmutable::parse('2026-08-20 10:05:00-03:00'),
            updatedAt: CarbonImmutable::parse('2026-08-20 10:05:00-03:00'),
            grossAmount: '12.00',
            discountAmount: '0.00',
            serviceChargeAmount: '0.00',
            deliveryAmount: '0.00',
            netAmount: '12.00',
            items: [new ExternalSaleItemData('ITEM-1', 'EXTERNAL-PRODUCT', 'SKU-1', 'Produto externo não cadastrado', '1', '12.00', '0.00', '12.00')],
            payments: [new ExternalSalePaymentData('PAY-1', 'PIX', 'Pix', null, '12.00', type: 'pix')],
            paidAmount: '12.00',
            changeAmount: '0.00',
        );

        $first = app(PdvOrderStagingService::class)->stage($this->connection, $sale);
        $second = app(PdvOrderStagingService::class)->stage($this->connection, $sale);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($before, $this->operationalCounts());
        $this->assertDatabaseCount('pdv_orders', 1);
        $this->assertDatabaseCount('pdv_order_items', 1);
        $this->assertDatabaseCount('pdv_order_payments', 1);
    }

    private function order(string $externalId, string $completedAt, string $total, string $state = PdvOrder::STATE_STAGED, string $status = 'concluido'): PdvOrder
    {
        return PdvOrder::query()->create([
            'pdv_connection_id' => $this->connection->id,
            'location_id' => $this->location->id,
            'external_order_id' => $externalId,
            'external_code' => $externalId,
            'external_status' => $status,
            'quantity' => '1',
            'subtotal' => $total,
            'discount_total' => '0',
            'total' => $total,
            'paid_total' => $total,
            'change_total' => '0',
            'external_created_at' => $completedAt,
            'external_completed_at' => $completedAt,
            'external_updated_at' => $completedAt,
            'source_hash' => str_repeat('a', 64),
            'latest_source_hash' => str_repeat('a', 64),
            'processing_state' => $state,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'imported_at' => $state === PdvOrder::STATE_IMPORTED ? now() : null,
        ]);
    }

    /** @return array<string, int> */
    private function operationalCounts(): array
    {
        return collect([
            'products',
            'product_categories',
            'product_prices',
            'product_recipes',
            'product_sales',
            'product_sale_orders',
            'product_sale_payments',
            'stock_movements',
            'ingredients',
            'preparations',
            'purchase_documents',
            'production_orders',
        ])
            ->mapWithKeys(fn (string $table): array => [$table => \DB::table($table)->count()])
            ->all();
    }
}
