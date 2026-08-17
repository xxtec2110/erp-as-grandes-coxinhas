<?php

namespace Tests\Feature;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\Location;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\IngredientPriceService;
use App\Services\ProductMarginService;
use App\Services\ProductRecipeService;
use App\Services\ProductSaleService;
use App\Services\StockMovementService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCostSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_change_snapshots_only_affected_recipe_and_sale_preserves_cost_and_margin(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $location = Location::query()->create(['name' => 'Loja', 'type' => 'store', 'active' => true]);
        $supplier = Supplier::query()->create(['name' => 'Fornecedor', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Queijo', 'base_unit' => 'g', 'active' => true]);
        $otherIngredient = Ingredient::query()->create(['name' => 'Farinha', 'base_unit' => 'g', 'active' => true]);
        $product = Product::query()->create(['name' => 'Coxinha', 'stock_unit' => 'un', 'active' => true]);
        $otherProduct = Product::query()->create(['name' => 'Outro', 'stock_unit' => 'un', 'active' => true]);
        $today = now()->toDateString();
        $prices = app(IngredientPriceService::class);
        $prices->record($ingredient, ['supplier_id' => $supplier->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'price_paid' => '40', 'effective_date' => now()->subDays(10)->toDateString(), 'is_current' => true]);
        $prices->record($otherIngredient, ['supplier_id' => $supplier->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'price_paid' => '10', 'effective_date' => now()->subDays(10)->toDateString(), 'is_current' => true]);
        app(ProductRecipeService::class)->save($product, ['yield_quantity' => '1', 'technical_loss_percentage' => '0', 'packaging_cost' => '1', 'selling_price' => '12', 'ingredients' => [['ingredient_id' => $ingredient->id, 'quantity' => '100', 'unit' => 'g']]], $user);
        app(ProductRecipeService::class)->save($otherProduct, ['yield_quantity' => '1', 'technical_loss_percentage' => '0', 'packaging_cost' => '0', 'selling_price' => '5', 'ingredients' => [['ingredient_id' => $otherIngredient->id, 'quantity' => '100', 'unit' => 'g']]], $user);

        $current = app(ProductMarginService::class)->current($product->fresh(['recipe', 'currentPrice']));
        $this->assertSame('5.00000000', $current['unit_cost']);
        $this->assertSame('140.0000', $current['markup_percentage']);
        $prices->record($ingredient, ['supplier_id' => $supplier->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'price_paid' => '50', 'effective_date' => $today, 'is_current' => true]);
        $updated = app(ProductMarginService::class)->current($product->fresh(['recipe', 'currentPrice']));
        $this->assertSame('6.00000000', $updated['unit_cost']);
        $this->assertSame(1, $otherProduct->costSnapshots()->count());

        app(StockMovementService::class)->record(new RecordStockMovementData($product->id, $location->id, StockMovementType::OpeningBalance, '10', $today, 'opening-cost-test'));
        $sale = app(ProductSaleService::class)->record(['product_id' => $product->id, 'location_id' => $location->id, 'quantity' => '2', 'unit_price' => '12', 'payment_method' => 'cash', 'operation_date' => $today, 'idempotency_key' => 'sale-cost-test'], $user);
        $this->assertSame('6.00000000', $sale->unit_cost_snapshot);
        $this->assertSame('12.00', $sale->total_cost_snapshot);
        $this->assertSame('12.00', $sale->gross_profit_snapshot);
        $this->assertSame('50.0000', $sale->gross_margin_percentage_snapshot);

        $prices->record($ingredient, ['supplier_id' => $supplier->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'price_paid' => '60', 'effective_date' => now()->addDay()->toDateString(), 'is_current' => true]);
        $this->assertSame('6.00000000', $sale->fresh()->unit_cost_snapshot);

        $retroactive = app(ProductSaleService::class)->record(['product_id' => $product->id, 'location_id' => $location->id, 'quantity' => '1', 'unit_price' => '12', 'payment_method' => 'cash', 'operation_date' => now()->subYear()->toDateString(), 'idempotency_key' => 'retroactive-sale-without-cost-history'], $user);
        $this->assertNull($retroactive->unit_cost_snapshot);
        $this->assertNull($retroactive->gross_profit_snapshot);
    }

    public function test_product_without_recipe_is_reported_as_pending_and_never_as_zero_cost(): void
    {
        $product = Product::query()->create(['name' => 'Sem ficha', 'stock_unit' => 'un', 'active' => true]);
        $row = app(ProductMarginService::class)->current($product);
        $this->assertSame('recipe_pending', $row['status']);
        $this->assertNull($row['unit_cost']);
    }

    public function test_incomplete_recipe_reports_partial_cost_and_missing_components_without_final_margin(): void
    {
        $user = User::factory()->create();
        $ingredient = Ingredient::query()->create(['name' => 'Muçarela', 'base_unit' => 'g', 'active' => true]);
        $product = Product::query()->create(['name' => 'Coxinha incompleta', 'stock_unit' => 'un', 'active' => true]);
        app(ProductRecipeService::class)->save($product, [
            'yield_quantity' => '10',
            'technical_loss_percentage' => '0',
            'packaging_cost' => '5',
            'selling_price' => '12',
            'ingredients' => [['ingredient_id' => $ingredient->id, 'quantity' => '100', 'unit' => 'g']],
        ], $user);

        $row = app(ProductMarginService::class)->current($product->fresh(['recipe', 'currentPrice']));

        $this->assertSame('incomplete_cost', $row['status']);
        $this->assertNull($row['unit_cost']);
        $this->assertSame('0.50000000', $row['partial_unit_cost']);
        $this->assertSame(['Insumo: Muçarela'], $row['missing_components']);
        $this->assertNull($row['gross_margin_percentage']);
    }
}
