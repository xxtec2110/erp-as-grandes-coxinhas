<?php

namespace Tests\Feature;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\IngredientStockMovement;
use App\Models\Location;
use App\Models\PdvConnection;
use App\Models\PdvOrder;
use App\Models\PdvOrderItem;
use App\Models\PdvPaymentMethodMapping;
use App\Models\PdvProductMapping;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\StockMovement;
use App\Models\User;
use App\Pdv\Data\ExternalSaleData;
use App\Pdv\Data\ExternalSaleItemData;
use App\Pdv\Data\ExternalSalePaymentData;
use App\Pdv\GrandChefQueryContract;
use App\Services\PdvOrderPreviewService;
use App\Services\PdvOrderReconciliationService;
use App\Services\PdvOrderStagingService;
use App\Services\StockMovementService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeGrandChefQueryContract;
use Tests\TestCase;

class PdvOrderStagingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Location $ibira;

    private Location $catanduva;

    private PdvConnection $ibiraConnection;

    private PdvConnection $catanduvaConnection;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $this->ibira = Location::query()->create(['name' => 'Unidade Ibirá', 'type' => 'store', 'active' => true]);
        $this->catanduva = Location::query()->create(['name' => 'Unidade Catanduva', 'type' => 'store', 'active' => true]);
        $this->ibiraConnection = PdvConnection::query()->firstOrFail();
        $this->ibiraConnection->update([
            'location_id' => $this->ibira->id,
            'name' => 'GrandChef Ibirá',
            'enabled' => true,
            'operational_start_at' => '2026-08-20 00:00:00-03:00',
            'configuration' => ['endpoint' => 'https://ibira.invalid/graphql'],
            'encrypted_credentials' => ['bearer_token' => 'fixture-bearer', 'device_token' => 'fixture-device'],
        ]);
        $this->catanduvaConnection = PdvConnection::query()->create([
            'location_id' => $this->catanduva->id,
            'provider' => 'grandchef',
            'name' => 'GrandChef Catanduva',
            'status' => 'not_configured',
            'enabled' => false,
            'operational_start_at' => '2026-08-20 00:00:00-03:00',
        ]);
        $this->product = Product::query()->create(['name' => 'Coxinha oficial', 'stock_unit' => 'un', 'active' => true]);
    }

    public function test_split_payment_staging_preserves_order_items_payments_decimals_and_dates_without_operational_writes(): void
    {
        $before = $this->operationalCounts();
        $order = app(PdvOrderStagingService::class)->stage($this->ibiraConnection, $this->sale());

        $this->assertDatabaseCount('pdv_orders', 1);
        $this->assertDatabaseCount('pdv_order_items', 3);
        $this->assertDatabaseCount('pdv_order_payments', 2);
        $this->assertSame('60.00', $order->total);
        $this->assertSame('6.000000', $order->quantity);
        $this->assertSame('2026-08-20 20:00:00', $order->external_created_at->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-20 20:15:00', $order->external_completed_at->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i:s'));
        $this->assertSame('1.000000', $order->items->firstWhere('external_item_id', 'ITEM-1')->quantity);
        $this->assertSame('10.0000', $order->items->firstWhere('external_item_id', 'ITEM-1')->unit_price);
        $this->assertSame('20.00', $order->payments->firstWhere('external_payment_id', 'PAY-1')->amount);
        $this->assertSame('0.00', $order->payments->firstWhere('external_payment_id', 'PAY-1')->fees);
        $this->assertSame(1, $order->payments->firstWhere('external_payment_id', 'PAY-1')->installment_number);
        $this->assertSame($before, $this->operationalCounts());
    }

    public function test_restage_is_idempotent_and_same_external_order_is_independent_between_connections(): void
    {
        $staging = app(PdvOrderStagingService::class);
        $first = $staging->stage($this->ibiraConnection, $this->sale());
        $second = $staging->stage($this->ibiraConnection, $this->sale());
        $catanduva = $staging->stage($this->catanduvaConnection, $this->sale(location: $this->catanduva));

        $this->assertSame($first->id, $second->id);
        $this->assertNotSame($first->id, $catanduva->id);
        $this->assertSame($first->source_hash, $second->source_hash);
        $this->assertDatabaseCount('pdv_orders', 2);
        $this->assertDatabaseCount('pdv_order_items', 6);
        $this->assertDatabaseCount('pdv_order_payments', 4);
        $this->assertDatabaseHas('pdv_orders', ['pdv_connection_id' => $this->ibiraConnection->id, 'external_order_id' => 'ORDER-1']);
        $this->assertDatabaseHas('pdv_orders', ['pdv_connection_id' => $this->catanduvaConnection->id, 'external_order_id' => 'ORDER-1']);
    }

    public function test_changed_staged_content_is_updated_without_deleting_history_and_imported_snapshot_is_not_rewritten(): void
    {
        $staging = app(PdvOrderStagingService::class);
        $order = $staging->stage($this->ibiraConnection, $this->sale());
        $originalHash = $order->source_hash;
        $changed = $this->sale(
            items: [$this->item('ITEM-1', 'P1', 'Produto 1', '2', '15', '30')],
            payments: [$this->payment('PAY-1', 'CASH', 'Dinheiro', '30')],
            total: '30',
            paid: '30',
            quantity: '2',
        );
        $order = $staging->stage($this->ibiraConnection, $changed);

        $this->assertNotSame($originalHash, $order->source_hash);
        $this->assertNotNull($order->source_changed_at);
        $this->assertSame('30.00', $order->total);
        $this->assertDatabaseCount('pdv_order_items', 3);
        $this->assertDatabaseCount('pdv_order_payments', 2);
        $this->assertSame(1, $order->items->where('present_in_latest', true)->count());
        $this->assertSame(1, $order->payments->where('present_in_latest', true)->count());
        $order->update(['processing_state' => PdvOrder::STATE_IMPORTED, 'imported_at' => now()]);
        $staging->stage($this->ibiraConnection, $this->sale(total: '99', paid: '99'));
        $order->refresh();
        $this->assertSame('30.00', $order->total);
        $this->assertNotSame($order->source_hash, $order->latest_source_hash);
    }

    public function test_reconciliation_requires_confirmed_mappings_from_the_same_connection(): void
    {
        $order = app(PdvOrderStagingService::class)->stage($this->ibiraConnection, $this->singleItemSale());
        PdvProductMapping::query()->create(['pdv_connection_id' => $this->catanduvaConnection->id, 'external_product_id' => 'P1', 'external_name' => 'Outro', 'product_id' => $this->product->id, 'status' => 'confirmed']);
        PdvPaymentMethodMapping::query()->create(['pdv_connection_id' => $this->catanduvaConnection->id, 'external_method_code' => 'CASH', 'external_name' => 'Dinheiro', 'payment_method' => 'cash', 'status' => 'confirmed']);
        $missing = app(PdvOrderReconciliationService::class)->reconcile($order);
        $this->assertContains('product_mapping_missing', collect($missing['blockers'])->pluck('code'));
        $this->assertContains('payment_mapping_missing', collect($missing['blockers'])->pluck('code'));

        PdvProductMapping::query()->create(['pdv_connection_id' => $this->ibiraConnection->id, 'external_product_id' => 'P1', 'external_name' => 'Produto', 'product_id' => $this->product->id, 'status' => 'pending']);
        PdvPaymentMethodMapping::query()->create(['pdv_connection_id' => $this->ibiraConnection->id, 'external_method_code' => 'CASH', 'external_name' => 'Dinheiro', 'payment_method' => 'cash', 'status' => 'pending']);
        $pending = app(PdvOrderReconciliationService::class)->reconcile($order);
        $this->assertContains('product_mapping_not_confirmed', collect($pending['blockers'])->pluck('code'));
        $this->assertContains('payment_mapping_not_confirmed', collect($pending['blockers'])->pluck('code'));
    }

    public function test_confirmed_mappings_still_block_insufficient_stock_and_become_ready_with_sufficient_stock(): void
    {
        $order = app(PdvOrderStagingService::class)->stage($this->ibiraConnection, $this->singleItemSale());
        $this->confirmMappings();
        $blocked = app(PdvOrderReconciliationService::class)->reconcile($order);
        $this->assertFalse($blocked['ready_for_import']);
        $this->assertContains('stock_insufficient', collect($blocked['blockers'])->pluck('code'));

        app(StockMovementService::class)->record(new RecordStockMovementData($this->product->id, $this->ibira->id, StockMovementType::OpeningBalance, '10', '2026-08-20', 'staging-test-opening'));
        $ready = app(PdvOrderReconciliationService::class)->reconcile($order);
        $this->assertTrue($ready['ready_for_import']);
        $this->assertSame('2.000000', $ready['stock_status']['products'][0]['required']);
        $this->assertSame('10.000000', $ready['stock_status']['products'][0]['available']);
        $this->assertTrue($ready['totals_status']['items_match']);
        $this->assertTrue($ready['totals_status']['payments_match']);
    }

    public function test_pix_is_preserved_as_supported_and_blocked_only_by_missing_mapping_while_cancelled_order_does_not_require_mappings(): void
    {
        $pix = app(PdvOrderStagingService::class)->stage($this->ibiraConnection, $this->singleItemSale(paymentCode: 'PIX', paymentName: 'Pix'));
        $pixResult = app(PdvOrderReconciliationService::class)->reconcile($pix);
        $this->assertDatabaseHas('pdv_order_payments', ['pdv_order_id' => $pix->id, 'external_form_id' => 'PIX', 'external_form_description' => 'Pix']);
        $this->assertContains('payment_mapping_missing', collect($pixResult['blockers'])->pluck('code'));
        $this->assertNotContains('payment_mapping_unsupported', collect($pixResult['blockers'])->pluck('code'));

        $cancelled = app(PdvOrderStagingService::class)->stage($this->ibiraConnection, $this->singleItemSale(id: 'ORDER-CANCELLED', status: 'cancelado'));
        $cancelledResult = app(PdvOrderReconciliationService::class)->reconcile($cancelled);
        $codes = collect($cancelledResult['blockers'])->pluck('code');
        $this->assertContains('order_cancelled', $codes);
        $this->assertNotContains('product_mapping_missing', $codes);
        $this->assertNotContains('payment_mapping_missing', $codes);
        $this->assertDatabaseCount('product_sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_preview_summarizes_split_payments_and_blockers_without_side_effects(): void
    {
        app(PdvOrderStagingService::class)->stage($this->ibiraConnection, $this->sale());
        $before = $this->operationalCounts();
        $result = app(PdvOrderPreviewService::class)->period(
            $this->ibiraConnection,
            CarbonImmutable::parse('2026-08-20', 'America/Sao_Paulo'),
            CarbonImmutable::parse('2026-08-20', 'America/Sao_Paulo'),
        );
        $this->assertSame(1, $result['summary']['staged']);
        $this->assertSame(0, $result['summary']['ready']);
        $this->assertSame(1, $result['summary']['blocked']);
        $this->assertSame(1, $result['summary']['split_payments']);
        $this->assertSame('60.00', $result['summary']['total']);
        $this->assertSame(2, count($result['orders'][0]['reconciliation']['payment_mapping_status']['payments']));
        $this->assertSame($before, $this->operationalCounts());
    }

    public function test_interface_requires_permission_and_location_scope_and_never_renders_credentials(): void
    {
        $order = app(PdvOrderStagingService::class)->stage($this->ibiraConnection, $this->sale());
        $this->get(route('pdv.staging.index', $this->ibiraConnection))->assertRedirect(route('login'));
        $restricted = User::factory()->unprivileged()->create();
        $restricted->permissions()->attach(Permission::query()->where('name', 'pdv.manage')->firstOrFail(), ['allowed' => true]);
        $this->actingAs($restricted)->get(route('pdv.staging.index', $this->ibiraConnection))->assertForbidden();
        $restricted->locations()->attach($this->ibira);
        $this->actingAs($restricted)->get(route('pdv.staging.index', $this->ibiraConnection))->assertOk();
        $this->actingAs($restricted)->get(route('pdv.staging.index', $this->catanduvaConnection))->assertForbidden();
        $response = $this->actingAs($this->admin)->get(route('pdv.staging.index', $this->ibiraConnection));
        $response->assertOk()->assertSee('Preparar não registra venda')->assertDontSee('fixture-bearer')->assertDontSee('fixture-device');
        $this->actingAs($this->admin)->get(route('pdv.staging.show', [$this->ibiraConnection, $order]))
            ->assertOk()
            ->assertSee('Todos os pagamentos externos são preservados individualmente')
            ->assertSee('Dinheiro')
            ->assertSee('Pix')
            ->assertSee('BLOQUEADO')
            ->assertDontSee('fixture-bearer')
            ->assertDontSee('fixture-device');
    }

    public function test_prepare_action_enforces_seven_days_and_stages_fake_transport_without_sales_or_stock(): void
    {
        $this->actingAs($this->admin)->post(route('pdv.staging.prepare', $this->ibiraConnection), ['from' => '2026-08-01', 'to' => '2026-08-08'])
            ->assertSessionHasErrors('to');
        $this->app->instance(GrandChefQueryContract::class, new FakeGrandChefQueryContract);
        Http::fake(['*' => Http::response(['data' => ['fixture' => ['orders' => [$this->fixtureOrder()], 'next_cursor' => null, 'total' => 1]]])]);
        $before = $this->operationalCounts();

        $this->actingAs($this->admin)->post(route('pdv.staging.prepare', $this->ibiraConnection), ['from' => '2026-08-20', 'to' => '2026-08-20'])
            ->assertRedirect(route('pdv.staging.index', [$this->ibiraConnection, 'from' => '2026-08-20', 'to' => '2026-08-20']))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('pdv_orders', 1);
        $this->assertDatabaseCount('pdv_order_items', 3);
        $this->assertDatabaseCount('pdv_order_payments', 2);
        $this->assertSame($before, $this->operationalCounts());
        Http::assertSentCount(1);
    }

    public function test_product_sale_link_is_nullable_unique_and_preserves_staging_history(): void
    {
        $order = app(PdvOrderStagingService::class)->stage($this->ibiraConnection, $this->singleItemSale());
        $item = $order->items->first();
        $this->assertTrue(Schema::hasColumn('product_sales', 'pdv_order_item_id'));
        $sale = $this->insertSale($item);
        $this->assertSame($item->id, $sale->pdv_order_item_id);
        $this->expectException(QueryException::class);
        $this->insertSale($item, 'duplicate-key');
    }

    private function confirmMappings(): void
    {
        PdvProductMapping::query()->create(['pdv_connection_id' => $this->ibiraConnection->id, 'external_product_id' => 'P1', 'external_name' => 'Produto', 'product_id' => $this->product->id, 'status' => 'confirmed']);
        PdvPaymentMethodMapping::query()->create(['pdv_connection_id' => $this->ibiraConnection->id, 'external_method_code' => 'CASH', 'external_name' => 'Dinheiro', 'payment_method' => 'cash', 'status' => 'confirmed']);
    }

    private function insertSale(PdvOrderItem $item, string $key = 'pdv-link-test'): ProductSale
    {
        return ProductSale::query()->create([
            'product_id' => $this->product->id,
            'location_id' => $this->ibira->id,
            'pdv_order_item_id' => $item->id,
            'quantity' => '1',
            'unit_price' => '10',
            'total_amount' => '10',
            'gross_amount' => '10',
            'net_amount' => '10',
            'operation_date' => '2026-08-20',
            'source' => 'test',
            'idempotency_key' => $key,
        ]);
    }

    private function singleItemSale(string $id = 'ORDER-SINGLE', string $status = 'concluido', string $paymentCode = 'CASH', string $paymentName = 'Dinheiro'): ExternalSaleData
    {
        return $this->sale(
            id: $id,
            status: $status,
            items: [$this->item('ITEM-1', 'P1', 'Produto 1', '2', '10', '20')],
            payments: [$this->payment('PAY-1', $paymentCode, $paymentName, '20')],
            total: '20',
            paid: '20',
            quantity: '2',
        );
    }

    /** @param array<int, ExternalSaleItemData>|null $items @param array<int, ExternalSalePaymentData>|null $payments */
    private function sale(string $id = 'ORDER-1', string $status = 'concluido', ?Location $location = null, ?array $items = null, ?array $payments = null, string $total = '60', string $paid = '60', string $quantity = '6'): ExternalSaleData
    {
        $created = CarbonImmutable::parse('2026-08-20 20:00:00', 'America/Sao_Paulo');
        $completed = CarbonImmutable::parse('2026-08-20 20:15:00', 'America/Sao_Paulo');
        $items ??= [
            $this->item('ITEM-1', 'P1', 'Produto 1', '1', '10', '10'),
            $this->item('ITEM-2', 'P2', 'Produto 2', '2', '10', '20'),
            $this->item('ITEM-3', 'P3', 'Produto 3', '3', '10', '30'),
        ];
        $payments ??= [
            $this->payment('PAY-1', 'CASH', 'Dinheiro', '20'),
            $this->payment('PAY-2', 'PIX', 'Pix', (string) ((int) $paid - 20)),
        ];

        return new ExternalSaleData(
            'grandchef',
            $id,
            '1001',
            (string) ($location ?? $this->ibira)->id,
            $status,
            $created,
            $completed,
            $completed,
            $total,
            '0',
            '0',
            '0',
            $total,
            $items,
            $payments,
            metadata: ['reported_quantity' => $quantity],
            paidAmount: $paid,
            changeAmount: '0',
        );
    }

    private function item(string $id, string $productId, string $name, string $quantity, string $unitPrice, string $total): ExternalSaleItemData
    {
        return new ExternalSaleItemData($id, $productId, 'SKU-'.$productId, $name, $quantity, $unitPrice, '0', $total, subtotal: $total, externalStatus: 'concluido');
    }

    private function payment(string $id, string $code, string $name, string $amount): ExternalSalePaymentData
    {
        return new ExternalSalePaymentData($id, $code, $name, null, $amount, 1, 'pago', 'receita', metadata: [], externalTotal: $amount, fees: '0', installmentNumber: 1, paidAt: '2026-08-20T20:15:00-03:00');
    }

    private function fixtureOrder(): array
    {
        return [
            'id' => 'ORDER-FAKE', 'code' => '1001', 'status' => 'concluido', 'closed_at' => '2026-08-20T20:15:00-03:00', 'gross_amount' => '60', 'net_amount' => '60', 'paid_amount' => '60', 'change_amount' => '0',
            'items' => [
                ['id' => 'ITEM-1', 'product_id' => 'P1', 'name' => 'Produto 1', 'quantity' => '1', 'unit_price' => '10', 'subtotal' => '10', 'total' => '10'],
                ['id' => 'ITEM-2', 'product_id' => 'P2', 'name' => 'Produto 2', 'quantity' => '2', 'unit_price' => '10', 'subtotal' => '20', 'total' => '20'],
                ['id' => 'ITEM-3', 'product_id' => 'P3', 'name' => 'Produto 3', 'quantity' => '3', 'unit_price' => '10', 'subtotal' => '30', 'total' => '30'],
            ],
            'payments' => [
                ['id' => 'PAY-1', 'method_code' => 'CASH', 'method_name' => 'Dinheiro', 'amount' => '20'],
                ['id' => 'PAY-2', 'method_code' => 'PIX', 'method_name' => 'Pix', 'amount' => '40'],
            ],
        ];
    }

    /** @return array<string, int> */
    private function operationalCounts(): array
    {
        return [
            'product_sales' => ProductSale::query()->count(),
            'stock_movements' => StockMovement::query()->count(),
            'ingredient_stock_movements' => IngredientStockMovement::query()->count(),
        ];
    }
}
