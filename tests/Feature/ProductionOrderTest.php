<?php

namespace Tests\Feature;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\Location;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\IngredientStockService;
use App\Services\ProductionOrderService;
use App\Services\ProductRecipeService;
use App\Services\StockMovementService;
use Database\Seeders\AuthorizationSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Location $location;

    private Ingredient $ingredient;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->user = User::factory()->create();
        $this->location = Location::query()->create(['name' => 'Fábrica', 'type' => 'production', 'active' => true]);
        $supplier = Supplier::query()->create(['name' => 'Fornecedor', 'active' => true]);
        $this->ingredient = Ingredient::query()->create(['name' => 'Farinha', 'base_unit' => 'g', 'active' => true]);
        $this->ingredient->prices()->create(['supplier_id' => $supplier->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'price_paid' => '10', 'effective_date' => now(), 'normalized_quantity' => '1000', 'base_unit_cost' => '0.01', 'is_current' => true]);
        $this->product = Product::query()->create(['name' => 'Coxinha', 'stock_unit' => 'un', 'active' => true]);
        app(ProductRecipeService::class)->save($this->product, ['yield_quantity' => '1', 'technical_loss_percentage' => '0', 'packaging_cost' => '1', 'selling_price' => '10', 'ingredients' => [['ingredient_id' => $this->ingredient->id, 'quantity' => '100', 'unit' => 'g']]]);
    }

    public function test_order_freezes_snapshot_completes_and_reverses_atomically(): void
    {
        app(IngredientStockService::class)->record(['ingredient_id' => $this->ingredient->id, 'location_id' => $this->location->id, 'type' => 'positive_adjustment', 'quantity_delta' => '2000', 'operation_date' => now()->toDateString(), 'idempotency_key' => 'opening', 'created_by' => $this->user->id]);
        $service = app(ProductionOrderService::class);
        $order = $service->plan(['location_id' => $this->location->id, 'production_date' => now()->toDateString(), 'idempotency_key' => 'order-1', 'items' => [['product_id' => $this->product->id, 'planned_quantity' => '10']]], $this->user);
        $this->assertSame('2.00000000', $order->items->sole()->unit_cost_snapshot);
        $this->ingredient->prices()->update(['base_unit_cost' => '0.99']);
        $completed = $service->complete($order, [$order->items->sole()->id => '10'], $this->user);
        $this->assertSame('20.00000000', $completed->items->sole()->total_cost_snapshot);
        $this->assertDatabaseHas('ingredient_stock_movements', ['type' => 'production_consumption', 'quantity_delta' => '-1000.000000']);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $this->product->id, 'quantity_delta' => '10.000000']);
        $service->reverse($completed, 'Produção lançada em duplicidade', $this->user);
        $service->reverse($completed->fresh(), 'Produção lançada em duplicidade', $this->user);
        $this->assertSame('2000.000000', app(IngredientStockService::class)->balance($this->ingredient->id, $this->location->id));
        $this->assertDatabaseCount('production_order_audits', 3);
    }

    public function test_insufficient_ingredient_rolls_back_entire_completion(): void
    {
        $service = app(ProductionOrderService::class);
        $order = $service->plan(['location_id' => $this->location->id, 'production_date' => now()->toDateString(), 'idempotency_key' => 'order-2', 'items' => [['product_id' => $this->product->id, 'planned_quantity' => '10']]], $this->user);
        try {
            $service->complete($order, [$order->items->sole()->id => '10'], $this->user);
            $this->fail('Deveria bloquear');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Estoque insuficiente', $e->getMessage());
        }
        $this->assertSame('planned', $order->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('ingredient_stock_movements', 0);
    }

    public function test_reversal_is_blocked_when_finished_product_balance_is_unavailable(): void
    {
        app(IngredientStockService::class)->record(['ingredient_id' => $this->ingredient->id, 'location_id' => $this->location->id, 'type' => 'positive_adjustment', 'quantity_delta' => '1000', 'operation_date' => now()->toDateString(), 'idempotency_key' => 'opening-2']);
        $service = app(ProductionOrderService::class);
        $order = $service->plan(['location_id' => $this->location->id, 'production_date' => now()->toDateString(), 'idempotency_key' => 'order-3', 'items' => [['product_id' => $this->product->id, 'planned_quantity' => '5']]], $this->user);
        $service->complete($order, [$order->items->sole()->id => '5'], $this->user);
        app(StockMovementService::class)->record(new RecordStockMovementData($this->product->id, $this->location->id, StockMovementType::Sale, '-5', now()->toDateString(), 'sold'));
        $this->expectException(DomainException::class);
        $service->reverse($order->fresh(), 'Tentativa inválida', $this->user);
    }

    public function test_operational_pages_are_authenticated_and_render(): void
    {
        $this->get(route('production-orders.index'))->assertRedirect(route('login'));
        $this->actingAs($this->user)->get(route('production-orders.index'))->assertOk()->assertSee('Ordens de produção');
        $this->actingAs($this->user)->get(route('ingredient-stock.index'))->assertOk()->assertSee('Estoque de insumos');
    }
}
