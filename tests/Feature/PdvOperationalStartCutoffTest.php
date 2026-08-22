<?php

namespace Tests\Feature;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\CatalogAdminAudit;
use App\Models\Location;
use App\Models\PdvConnection;
use App\Models\PdvOrder;
use App\Models\PdvOrderItem;
use App\Models\PdvOrderPayment;
use App\Models\PdvPaymentMethodMapping;
use App\Models\PdvProductMapping;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\ProductSaleOrder;
use App\Models\ProductSalePayment;
use App\Models\StockMovement;
use App\Models\User;
use App\Pdv\Data\PdvPage;
use App\Pdv\IntegrationNotConfiguredException;
use App\Pdv\PdvOrderImportBlockedException;
use App\Pdv\PdvProviderInterface;
use App\Pdv\PdvProviderManager;
use App\Services\OpeningStockService;
use App\Services\PdvGoLiveService;
use App\Services\PdvOperationalStartService;
use App\Services\PdvOrderBatchImportService;
use App\Services\PdvOrderImportPlanService;
use App\Services\PdvOrderImportService;
use App\Services\PdvSyncService;
use App\Services\StockMovementService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class PdvOperationalStartCutoffTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Location $location;

    private Location $otherLocation;

    private PdvConnection $connection;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create(['is_super_admin' => true, 'all_locations_access' => true]);
        $this->location = Location::query()->create(['name' => 'Unidade Ibirá', 'type' => Location::TYPE_STORE, 'active' => true]);
        $this->otherLocation = Location::query()->create(['name' => 'Catanduva', 'type' => Location::TYPE_STORE, 'active' => true]);
        $this->connection = PdvConnection::query()->firstOrFail();
        $this->connection->update([
            'location_id' => $this->location->id,
            'provider' => 'grandchef',
            'name' => 'GrandChef Ibirá',
            'enabled' => true,
            'configuration' => ['endpoint' => 'https://fixture.invalid/graphql'],
            'encrypted_credentials' => ['bearer_token' => 'fixture-bearer', 'device_token' => 'fixture-device'],
        ]);
        $this->product = Product::query()->create(['name' => 'Frango com catupiry', 'stock_unit' => Product::UNIT_COUNT, 'active' => true]);
        PdvProductMapping::query()->create(['pdv_connection_id' => $this->connection->id, 'external_product_id' => 'P1', 'external_name' => $this->product->name, 'product_id' => $this->product->id, 'status' => 'confirmed']);
        PdvPaymentMethodMapping::query()->create(['pdv_connection_id' => $this->connection->id, 'external_method_code' => 'CASH', 'external_name' => 'Dinheiro', 'payment_method' => 'cash', 'status' => 'confirmed']);
    }

    public function test_missing_start_and_missing_completion_are_blocked_with_zero_operational_effects_and_visible_history(): void
    {
        $order = $this->order('ORDER-PENDING', '2026-08-20 15:10:00-03:00');
        $plan = app(PdvOrderImportPlanService::class)->plan($order);

        $this->assertFalse($plan['importable_by_cutoff']);
        $this->assertSame('operational_start_pending', $plan['operational_classification']);
        $this->assertNull($plan['operational_start_at']);
        $this->assertContains('operational_start_not_set', collect($plan['blockers'])->pluck('code'));
        $this->assertCount(0, $plan['items']);
        $this->assertCount(0, $plan['movements']);

        config()->set('pdv.import_enabled', true);
        try {
            app(PdvOrderImportService::class)->execute($order, $this->admin);
            $this->fail('O backend deveria bloquear uma conexão sem marco.');
        } catch (PdvOrderImportBlockedException $exception) {
            $this->assertSame('operational_start_not_set', $exception->blockers[0]['code']);
        }
        $this->assertOfficialEffects(0);

        $this->actingAs($this->admin)
            ->get(route('pdv.staging.index', [$this->connection, 'from' => '2026-08-20', 'to' => '2026-08-20']))
            ->assertOk()
            ->assertSee('ORDER-PENDING')
            ->assertSee('PRÉ-OPERAÇÃO · MARCO PENDENTE');
        $this->actingAs($this->admin)
            ->get(route('pdv.go-live', [$this->connection, 'from' => '2026-08-20', 'to' => '2026-08-20']))
            ->assertOk()
            ->assertSee('NÃO DEFINIDO')
            ->assertSee('O estoque do GrandChef não será importado')
            ->assertSee('necessidade operacional 0,00');

        $this->connection->update(['operational_start_at' => '2026-08-21 00:00:00-03:00']);
        $missingDate = $this->order('ORDER-NO-DATE', null);
        $missingPlan = app(PdvOrderImportPlanService::class)->plan($missingDate);
        $this->assertSame('operation_date_missing', $missingPlan['operational_classification']);
        $this->assertContains('operation_date_missing', collect($missingPlan['blockers'])->pluck('code'));
    }

    public function test_cutoff_is_timezone_safe_and_includes_the_exact_boundary_and_following_days(): void
    {
        $this->connection->update(['operational_start_at' => '2026-08-21 00:00:00-03:00']);
        $this->openingStock('100');

        $before = app(PdvOrderImportPlanService::class)->plan($this->order('ORDER-BEFORE', '2026-08-20 23:59:59-03:00'));
        $exact = app(PdvOrderImportPlanService::class)->plan($this->order('ORDER-EXACT', '2026-08-21 00:00:00-03:00'));
        $after = app(PdvOrderImportPlanService::class)->plan($this->order('ORDER-AFTER', '2026-08-21 00:00:01-03:00'));
        $otherDay = app(PdvOrderImportPlanService::class)->plan($this->order('ORDER-NEXT-DAY', '2026-08-22 10:00:00-03:00'));

        $this->assertSame('pre_operational', $before['operational_classification']);
        $this->assertFalse($before['importable_by_cutoff']);
        $this->assertContains('before_operational_start', collect($before['blockers'])->pluck('code'));
        $this->assertTrue($exact['importable_by_cutoff']);
        $this->assertTrue($exact['is_after_operational_start']);
        $this->assertTrue($exact['ready']);
        $this->assertTrue($after['ready']);
        $this->assertTrue($otherDay['ready']);
        $this->assertStringContainsString('-03:00', $exact['operational_start_at']);
        $this->assertSame('2026-08-21T00:00:00-03:00', $exact['order_completed_at']);
    }

    public function test_backend_and_batch_reject_pre_operational_orders_without_new_effects(): void
    {
        $this->connection->update(['operational_start_at' => '2026-08-21 08:00:00-03:00']);
        $this->openingStock('10');
        $before = StockMovement::query()->count();
        $order = $this->order('ORDER-HISTORICAL', '2026-08-20 12:00:00-03:00');
        config()->set('pdv.import_enabled', true);

        try {
            app(PdvOrderImportService::class)->execute($order, $this->admin);
            $this->fail('O pedido anterior ao marco não poderia ser importado.');
        } catch (PdvOrderImportBlockedException $exception) {
            $this->assertSame('before_operational_start', $exception->blockers[0]['code']);
        }

        $batch = app(PdvOrderBatchImportService::class)->execute([$order], $this->admin);
        $this->assertSame('failed', $batch[0]['status']);
        $this->assertSame('PdvOrderImportBlockedException', $batch[0]['error']);
        $this->assertOfficialEffects($before);
    }

    public function test_human_start_flow_is_authorized_idempotent_audited_and_protects_imported_history(): void
    {
        $firstKey = (string) Str::uuid();
        $payload = ['operational_start_date' => '2026-08-21', 'operational_start_time' => '08:00', 'confirmed' => '1', 'idempotency_key' => $firstKey];
        $this->actingAs($this->admin)->put(route('pdv.go-live.operational-start.update', $this->connection), $payload)
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('2026-08-21 08:00:00', $this->connection->fresh()->operational_start_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'));
        $audit = CatalogAdminAudit::query()->where('tool_name', 'pdv.operational_start.update')->firstOrFail();
        $this->assertSame(['operational_start_at'], array_keys($audit->before_values));
        $this->assertSame(['operational_start_at'], array_keys($audit->after_values));
        $this->assertStringNotContainsString('fixture-bearer', json_encode($audit->toArray()));
        $this->assertStringNotContainsString('fixture-device', json_encode($audit->toArray()));

        app(PdvOperationalStartService::class)->set($this->connection->fresh(), CarbonImmutable::parse('2026-08-21 08:00:00', config('app.timezone')), $this->admin, $firstKey);
        $this->assertDatabaseCount('catalog_admin_audits', 1);

        $secondKey = (string) Str::uuid();
        $this->actingAs($this->admin)->put(route('pdv.go-live.operational-start.update', $this->connection), ['operational_start_date' => '2026-08-21', 'operational_start_time' => '07:30', 'confirmed' => '1', 'idempotency_key' => $secondKey])->assertRedirect();
        $this->assertDatabaseCount('catalog_admin_audits', 2);

        $restricted = User::factory()->unprivileged()->create();
        $restricted->permissions()->attach(Permission::query()->where('name', 'pdv.manage')->firstOrFail(), ['allowed' => true]);
        $restricted->locations()->attach($this->otherLocation);
        $this->actingAs($restricted)->put(route('pdv.go-live.operational-start.update', $this->connection), ['operational_start_date' => '2026-08-21', 'operational_start_time' => '07:00', 'confirmed' => '1', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();

        $order = $this->order('ORDER-IMPORTED', '2026-08-21 08:00:00-03:00');
        ProductSaleOrder::query()->create([
            'location_id' => $this->location->id,
            'pdv_connection_id' => $this->connection->id,
            'pdv_order_id' => $order->id,
            'operation_date' => '2026-08-21',
            'entry_source' => 'pdv',
            'external_reference' => $order->external_order_id,
            'status' => ProductSaleOrder::STATUS_COMPLETED,
            'subtotal_snapshot' => '10',
            'discount_total_snapshot' => '0',
            'service_total_snapshot' => '0',
            'delivery_total_snapshot' => '0',
            'total_amount_snapshot' => '10',
            'paid_total_snapshot' => '10',
            'change_total_snapshot' => '0',
            'imported_at' => now(),
            'idempotency_key' => 'official-imported-fixture',
        ]);
        $this->actingAs($this->admin)->put(route('pdv.go-live.operational-start.update', $this->connection), ['operational_start_date' => '2026-08-21', 'operational_start_time' => '09:00', 'confirmed' => '1', 'idempotency_key' => (string) Str::uuid()])
            ->assertRedirect()->assertSessionHas('error', 'O novo marco invalidaria uma venda oficial já importada e não pode ser aplicado por este fluxo.');
        $this->assertSame('07:30', $this->connection->fresh()->operational_start_at->setTimezone(config('app.timezone'))->format('H:i'));
    }

    public function test_sync_uses_the_official_start_and_only_opening_stock_creates_initial_balance(): void
    {
        config()->set('pdv.enabled', true);
        config()->set('pdv.sync_enabled', true);
        $manager = Mockery::mock(PdvProviderManager::class);
        $manager->shouldNotReceive('for');
        $this->app->instance(PdvProviderManager::class, $manager);
        $this->expectException(IntegrationNotConfiguredException::class);
        try {
            app(PdvSyncService::class)->sync($this->connection->fresh(), $this->admin);
        } finally {
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_sync_after_start_stages_only_and_opening_stock_remains_the_single_initial_source(): void
    {
        $start = CarbonImmutable::parse('2026-08-21 08:00:00', config('app.timezone'));
        app(PdvOperationalStartService::class)->set($this->connection, $start, $this->admin, (string) Str::uuid());
        config()->set('pdv.enabled', true);
        config()->set('pdv.sync_enabled', true);
        $provider = Mockery::mock(PdvProviderInterface::class);
        $provider->shouldReceive('fetchSales')->once()->withArgs(function (PdvConnection $connection, ?array $cursor, ?CarbonImmutable $from, ?CarbonImmutable $to) use ($start): bool {
            $this->assertSame($this->connection->id, $connection->id);
            $this->assertNull($cursor);
            $this->assertTrue($from->equalTo($start));
            $this->assertTrue($to->greaterThanOrEqualTo($from));

            return true;
        })->andReturn(new PdvPage([]));
        $manager = Mockery::mock(PdvProviderManager::class);
        $manager->shouldReceive('for')->once()->andReturn($provider);
        $this->app->instance(PdvProviderManager::class, $manager);

        $result = app(PdvSyncService::class)->sync($this->connection->fresh(), $this->admin);
        $this->assertSame(0, $result['staged']);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->order('ORDER-FUTURE-STAGED', '2026-08-21 09:00:00-03:00');
        $this->assertSame(1, app(PdvGoLiveService::class)->build($this->connection->fresh(), $start->startOfDay(), $start->endOfDay())['opening_stock_pending']);

        $preview = app(OpeningStockService::class)->preview([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => '5',
            'operation_date' => '2026-08-21',
            'notes' => 'Contagem física oficial.',
            'idempotency_key' => (string) Str::uuid(),
        ], $this->admin);
        app(OpeningStockService::class)->confirm($preview['token'], $this->admin);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(StockMovementType::OpeningBalance, StockMovement::query()->firstOrFail()->type);
    }

    private function order(string $externalId, ?string $completedAt): PdvOrder
    {
        $order = PdvOrder::query()->create([
            'pdv_connection_id' => $this->connection->id,
            'location_id' => $this->location->id,
            'external_order_id' => $externalId,
            'external_code' => $externalId,
            'external_status' => 'concluido',
            'quantity' => '1',
            'service_total' => '0',
            'delivery_total' => '0',
            'subtotal' => '10',
            'discount_total' => '0',
            'total' => '10',
            'paid_total' => '10',
            'change_total' => '0',
            'external_created_at' => $completedAt,
            'external_completed_at' => $completedAt,
            'external_updated_at' => $completedAt,
            'source_hash' => str_repeat('a', 64),
            'latest_source_hash' => str_repeat('a', 64),
            'processing_state' => PdvOrder::STATE_STAGED,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        PdvOrderItem::query()->create(['pdv_order_id' => $order->id, 'external_item_id' => 'ITEM-'.$externalId, 'external_product_id' => 'P1', 'description' => $this->product->name, 'quantity' => '1', 'unit_price' => '10', 'subtotal' => '10', 'total' => '10', 'external_status' => 'concluido', 'cancelled' => false, 'present_in_latest' => true, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        PdvOrderPayment::query()->create(['pdv_order_id' => $order->id, 'external_payment_id' => 'PAY-'.$externalId, 'external_form_id' => 'CASH', 'external_form_description' => 'Dinheiro', 'external_type' => 'dinheiro', 'amount' => '10', 'external_total' => '10', 'fees' => '0', 'external_status' => 'pago', 'present_in_latest' => true, 'first_seen_at' => now(), 'last_seen_at' => now()]);

        return $order->load(['connection', 'location', 'items', 'payments']);
    }

    private function openingStock(string $quantity): void
    {
        app(StockMovementService::class)->record(new RecordStockMovementData(
            $this->product->id,
            $this->location->id,
            StockMovementType::OpeningBalance,
            $quantity,
            '2026-08-20',
            'opening-cutoff-test',
        ));
    }

    private function assertOfficialEffects(int $stockMovements): void
    {
        $this->assertSame(0, ProductSaleOrder::query()->count());
        $this->assertSame(0, ProductSale::query()->count());
        $this->assertSame(0, ProductSalePayment::query()->count());
        $this->assertSame($stockMovements, StockMovement::query()->count());
    }
}
