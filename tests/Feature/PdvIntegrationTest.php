<?php

namespace Tests\Feature;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\PdvConnection;
use App\Models\PdvLocationMapping;
use App\Models\PdvOrder;
use App\Models\PdvProductMapping;
use App\Models\Product;
use App\Models\User;
use App\Pdv\Data\ExternalSaleData;
use App\Pdv\Data\ExternalSaleItemData;
use App\Pdv\FakePdvProvider;
use App\Pdv\GrandChefPdvProvider;
use App\Pdv\IntegrationNotConfiguredException;
use App\Pdv\PdvProviderManager;
use App\Services\PdvInboundService;
use App\Services\PdvSaleImportService;
use App\Services\PdvSyncService;
use App\Services\StockBalanceService;
use App\Services\StockMovementService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PdvIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Location $location;

    private Product $product;

    private PdvConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('pdv.import_enabled', true);
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $this->location = Location::query()->create(['name' => 'Loja', 'type' => 'store', 'active' => true]);
        $this->product = Product::query()->create(['name' => 'Frango', 'stock_unit' => 'un', 'active' => true]);
        $this->connection = PdvConnection::query()->firstOrFail();
        $this->connection->update(['location_id' => $this->location->id, 'provider' => 'fake']);
        PdvLocationMapping::query()->create(['pdv_connection_id' => $this->connection->id, 'external_location_id' => 'L1', 'external_name' => 'Loja 1', 'location_id' => $this->location->id, 'status' => 'confirmed']);
        PdvProductMapping::query()->create(['pdv_connection_id' => $this->connection->id, 'external_product_id' => 'P1', 'external_name' => 'Frango', 'product_id' => $this->product->id, 'status' => 'confirmed', 'match_source' => 'admin']);
        app(StockMovementService::class)->record(new RecordStockMovementData($this->product->id, $this->location->id, StockMovementType::OpeningBalance, '100', '2026-08-14', 'pdv-opening'));
    }

    public function test_grandchef_is_explicitly_not_configured_without_network_call(): void
    {
        $this->expectException(IntegrationNotConfiguredException::class);
        app(GrandChefPdvProvider::class)->testConnection($this->connection);
    }

    public function test_import_uses_official_sale_and_stock_once(): void
    {
        $event = app(PdvInboundService::class)->receive($this->connection, 'event-1', 'sale.closed', ['safe' => true], 'S1');
        $first = app(PdvSaleImportService::class)->import($this->connection, $this->sale(), $this->admin, $event);
        $second = app(PdvSaleImportService::class)->import($this->connection, $this->sale(), $this->admin, $event->fresh());
        $this->assertSame('imported', $first['status']);
        $this->assertSame('imported', $second['status']);
        $this->assertDatabaseCount('product_sales', 1);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertSame('98.000000', app(StockBalanceService::class)->balance($this->product, $this->location));
        $this->assertDatabaseHas('product_sales', ['source' => 'pdv', 'external_sale_id' => 'S1', 'external_item_id' => 'I1']);
    }

    public function test_unknown_product_waits_for_mapping_and_can_be_reprocessed(): void
    {
        $data = $this->sale('UNKNOWN');
        $event = app(PdvInboundService::class)->receive($this->connection, 'event-2', 'sale.closed', [], 'S1');
        $result = app(PdvSaleImportService::class)->import($this->connection, $data, $this->admin, $event);
        $this->assertSame('waiting_mapping', $result['status']);
        $this->assertDatabaseCount('product_sales', 0);
        PdvProductMapping::query()->create(['pdv_connection_id' => $this->connection->id, 'external_product_id' => 'UNKNOWN', 'external_name' => 'Desconhecido', 'product_id' => $this->product->id, 'status' => 'confirmed', 'match_source' => 'admin']);
        $this->assertSame('imported', app(PdvSaleImportService::class)->import($this->connection, $data, $this->admin, $event->fresh())['status']);
        $this->assertDatabaseCount('product_sales', 1);
    }

    public function test_cancellation_reverses_stock_without_deleting_sale(): void
    {
        $event = app(PdvInboundService::class)->receive($this->connection, 'event-3', 'sale.closed', [], 'S1');
        app(PdvSaleImportService::class)->import($this->connection, $this->sale(), $this->admin, $event);
        $cancel = $this->sale(status: 'cancelled');
        $cancelEvent = app(PdvInboundService::class)->receive($this->connection, 'event-4', 'sale.cancelled', [], 'S1');
        app(PdvSaleImportService::class)->import($this->connection, $cancel, $this->admin, $cancelEvent);
        app(PdvSaleImportService::class)->import($this->connection, $cancel, $this->admin, $cancelEvent->fresh());
        $this->assertDatabaseCount('product_sales', 1);
        $this->assertDatabaseHas('product_sales', ['external_sale_id' => 'S1', 'external_status' => 'cancelled']);
        $this->assertSame('100.000000', app(StockBalanceService::class)->balance($this->product, $this->location));
        $this->assertDatabaseCount('stock_movements', 3);
    }

    public function test_admin_panel_is_protected_and_webhook_stays_off(): void
    {
        $this->get(route('pdv.index'))->assertRedirect(route('login'));
        $this->actingAs($this->admin)->get(route('pdv.index'))->assertOk()->assertSee('GrandChef por unidade');
        $this->postJson(route('webhooks.pdv.receive', ['provider' => 'grandchef']), [])->assertNotFound();
        config()->set('pdv.webhook_enabled', true);
        $this->postJson(route('webhooks.pdv.receive', ['provider' => 'grandchef']), [])->assertStatus(501);
        $this->assertDatabaseCount('pdv_orders', 0);
        $this->assertDatabaseCount('product_sales', 0);
    }

    public function test_grandchef_is_forbidden_from_using_the_legacy_direct_importer(): void
    {
        $this->connection->update(['provider' => 'grandchef']);
        $this->expectException(IntegrationNotConfiguredException::class);
        $this->expectExceptionMessage('staging oficial');

        app(PdvSaleImportService::class)->import($this->connection->fresh(), $this->sale(), $this->admin);
    }

    public function test_grandchef_sync_places_orders_only_in_staging_even_when_import_flag_is_on(): void
    {
        $this->connection->update(['provider' => 'grandchef', 'enabled' => true]);
        config()->set('pdv.enabled', true);
        config()->set('pdv.sync_enabled', true);
        config()->set('pdv.import_enabled', true);
        $provider = new FakePdvProvider;
        $provider->setSales([$this->sale(location: (string) $this->location->id)]);
        $manager = Mockery::mock(PdvProviderManager::class);
        $manager->shouldReceive('for')->once()->andReturn($provider);
        $this->app->instance(PdvProviderManager::class, $manager);

        $result = app(PdvSyncService::class)->sync($this->connection->fresh(), $this->admin);

        $this->assertSame(1, $result['staged']);
        $this->assertSame(0, $result['imported']);
        $this->assertDatabaseCount('pdv_orders', 1);
        $this->assertDatabaseCount('product_sales', 0);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(PdvOrder::STATE_STAGED, PdvOrder::query()->firstOrFail()->processing_state);
    }

    public function test_grandchef_legacy_reprocess_route_can_only_refresh_staging(): void
    {
        $this->connection->update(['provider' => 'grandchef', 'enabled' => true]);
        $sale = $this->sale(location: (string) $this->location->id);
        $provider = new FakePdvProvider;
        $provider->setSales([$sale]);
        $manager = Mockery::mock(PdvProviderManager::class);
        $manager->shouldReceive('for')->once()->andReturn($provider);
        $this->app->instance(PdvProviderManager::class, $manager);
        $event = app(PdvInboundService::class)->receive($this->connection->fresh(), 'event-reprocess', 'sale.updated', [], $sale->externalSaleId);

        $this->actingAs($this->admin)->post(route('pdv.events.reprocess', $event))
            ->assertRedirect()->assertSessionHas('success', 'Evento reprocessado no staging; nenhuma venda ou baixa de estoque foi criada.');
        $this->assertDatabaseCount('pdv_orders', 1);
        $this->assertDatabaseCount('product_sales', 0);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('pdv_inbound_events', ['id' => $event->id, 'status' => 'received']);
    }

    private function sale(string $product = 'P1', string $status = 'closed', string $location = 'L1'): ExternalSaleData
    {
        $at = CarbonImmutable::parse('2026-08-14 12:00:00', 'America/Sao_Paulo');

        return new ExternalSaleData('grandchef', 'S1', '1001', $location, $status, $at, $at, $at, '20.00', '0.00', '0.00', '0.00', '20.00', [new ExternalSaleItemData('I1', $product, null, 'Frango', '2.000000', '10.0000', '0.00', '20.00')]);
    }
}
