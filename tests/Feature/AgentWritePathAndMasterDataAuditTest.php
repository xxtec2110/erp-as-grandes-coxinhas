<?php

namespace Tests\Feature;

use App\Agent\AgentToolExecutor;
use App\Agent\AgentToolRegistry;
use App\Models\Ingredient;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CatalogAgentToolService;
use App\Services\CreatePayableService;
use App\Services\CreatePurchaseDocumentService;
use App\Services\IngredientPriceService;
use App\Services\OpeningStockService;
use App\Services\ProductionOrderService;
use App\Services\ProductLossService;
use App\Services\ProductPriceService;
use App\Services\ProductRecipeService;
use App\Services\PurchaseReceiptService;
use App\Services\RegisterPaymentService;
use App\Services\StockTransferService;
use App\Services\UnitConversionService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentWritePathAndMasterDataAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_catalog_excludes_legacy_production_and_uses_only_order_workflow(): void
    {
        $registry = app(AgentToolRegistry::class);

        $this->assertNull($registry->get('production.plan'));
        $this->assertNull($registry->get('production.complete'));

        foreach (['production.orders.plan', 'production.orders.complete_batch'] as $name) {
            $tool = $registry->get($name);
            $this->assertNotNull($tool);
            $this->assertSame(ProductionOrderService::class, $tool->serviceClass);
            $this->assertTrue($tool->writesData);
            $this->assertTrue($tool->confirmationRequired);
            $this->assertTrue($tool->locationScoped);
        }
    }

    public function test_batch_completion_requires_both_order_permissions_before_preview_or_execution(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $location = Location::query()->create(['name' => 'Fábrica', 'type' => 'production', 'active' => true]);
        $user = User::factory()->unprivileged()->create();
        $user->permissions()->attach(Permission::query()->where('name', 'production.orders.complete')->firstOrFail(), ['allowed' => true]);
        $user->locations()->attach($location);

        $this->expectException(AuthorizationException::class);
        app(AgentToolExecutor::class)->execute('production.orders.complete_batch', [
            'location_id' => $location->id,
            'production_date' => '2026-08-24',
            'items' => [],
            'idempotency_key' => 'missing-create-permission',
        ], $user);
    }

    public function test_all_critical_write_tools_require_confirmation_and_delegate_to_domain_services(): void
    {
        $expected = [
            'catalog.products.create' => CatalogAgentToolService::class,
            'catalog.products.update' => CatalogAgentToolService::class,
            'catalog.products.update_price' => CatalogAgentToolService::class,
            'catalog.ingredients.create' => CatalogAgentToolService::class,
            'catalog.ingredients.update' => CatalogAgentToolService::class,
            'catalog.ingredient_prices.add' => CatalogAgentToolService::class,
            'catalog.suppliers.create' => CatalogAgentToolService::class,
            'catalog.suppliers.update' => CatalogAgentToolService::class,
            'catalog.preparations.create' => CatalogAgentToolService::class,
            'catalog.preparations.update' => CatalogAgentToolService::class,
            'catalog.product_recipes.create' => CatalogAgentToolService::class,
            'catalog.product_recipes.update' => CatalogAgentToolService::class,
            'purchases.documents.create' => CreatePurchaseDocumentService::class,
            'purchases.receipts.receive' => PurchaseReceiptService::class,
            'finance.payables.create' => CreatePayableService::class,
            'finance.payments.record' => RegisterPaymentService::class,
            'production.orders.plan' => ProductionOrderService::class,
            'production.orders.complete_batch' => ProductionOrderService::class,
            'losses.record' => ProductLossService::class,
            'transfers.create' => StockTransferService::class,
            'transfers.dispatch' => StockTransferService::class,
            'transfers.receive' => StockTransferService::class,
            'stock.opening_balance.record' => OpeningStockService::class,
        ];

        $registry = app(AgentToolRegistry::class);
        foreach ($expected as $name => $service) {
            $tool = $registry->get($name);
            $this->assertNotNull($tool, "Tool {$name} não registrada.");
            $this->assertTrue($tool->writesData, "Tool {$name} deveria ser gravável.");
            $this->assertTrue($tool->confirmationRequired, "Tool {$name} deveria exigir confirmação.");
            $this->assertSame($service, $tool->serviceClass, "Tool {$name} não delega ao Service oficial esperado.");
        }

        $executorSource = file_get_contents(app_path('Agent/AgentToolExecutor.php'));
        $this->assertStringNotContainsString('::create(', $executorSource);
        $this->assertStringNotContainsString('->update(', $executorSource);
        $this->assertStringNotContainsString('->save(', $executorSource);
    }

    public function test_recipe_header_update_preserves_components_and_old_production_snapshot(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create(['is_super_admin' => true]);
        $location = Location::query()->create(['name' => 'Fábrica', 'type' => 'production', 'active' => true]);
        $supplier = Supplier::query()->create(['name' => 'Fornecedor', 'active' => true]);
        $ingredientX = $this->pricedIngredient('Insumo X', $supplier);
        $ingredientY = $this->pricedIngredient('Insumo Y', $supplier);
        $product = Product::query()->create(['name' => 'Produto A', 'stock_unit' => 'un', 'active' => true]);
        app(ProductRecipeService::class)->save($product, [
            'yield_quantity' => '1',
            'technical_loss_percentage' => '0',
            'packaging_cost' => '0',
            'ingredients' => [
                ['ingredient_id' => $ingredientX->id, 'quantity' => '2', 'unit' => 'g'],
                ['ingredient_id' => $ingredientY->id, 'quantity' => '1', 'unit' => 'g'],
            ],
        ]);

        app(AgentToolExecutor::class)->execute('catalog.product_recipes.update', [
            'product_id' => $product->id,
            'yield_quantity' => '1',
            'technical_loss_percentage' => '0',
            'packaging_cost' => '0.50',
            'idempotency_key' => 'recipe-header-update',
        ], $user, true);
        $this->assertCount(2, $product->recipe->fresh('ingredients')->ingredients);

        $order = app(ProductionOrderService::class)->plan([
            'location_id' => $location->id,
            'production_date' => '2026-08-24',
            'idempotency_key' => 'snapshot-before-recipe-change',
            'items' => [['product_id' => $product->id, 'planned_quantity' => '10']],
        ], $user, 'agent');
        $snapshotBefore = $order->items->sole()->recipe_snapshot;

        app(AgentToolExecutor::class)->execute('catalog.product_recipes.update', [
            'product_id' => $product->id,
            'yield_quantity' => '1',
            'technical_loss_percentage' => '0',
            'packaging_cost' => '0.50',
            'ingredients' => [
                ['ingredient_id' => $ingredientX->id, 'quantity' => '3', 'unit' => 'g'],
                ['ingredient_id' => $ingredientY->id, 'quantity' => '1', 'unit' => 'g'],
            ],
            'preparations' => [],
            'idempotency_key' => 'recipe-component-update',
        ], $user, true);

        $snapshotAfter = $order->fresh('items')->items->sole()->recipe_snapshot;
        $this->assertSame($snapshotBefore, $snapshotAfter);
        $this->assertSame('2.00000000', collect($snapshotAfter['consumption_per_product'])->firstWhere('ingredient_id', $ingredientX->id)['quantity']);
        $this->assertSame('3.000000', $product->recipe->fresh('ingredients')->ingredients->firstWhere('ingredient_id', $ingredientX->id)->quantity);
    }

    public function test_unit_conversion_and_price_services_preserve_decimal_history(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Fornecedor', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Muçarela', 'base_unit' => 'g', 'active' => true]);

        $this->assertSame('4000.000000', app(UnitConversionService::class)->normalize('4', 'kg', 'g'));
        $first = app(IngredientPriceService::class)->record($ingredient, ['supplier_id' => $supplier->id, 'purchase_quantity' => '4', 'purchase_unit' => 'kg', 'price_paid' => '80', 'effective_date' => '2026-08-20']);
        $second = app(IngredientPriceService::class)->record($ingredient, ['supplier_id' => $supplier->id, 'purchase_quantity' => '2.5', 'purchase_unit' => 'kg', 'price_paid' => '55', 'effective_date' => '2026-08-24']);
        $this->assertSame('0.02000000', $first->base_unit_cost);
        $this->assertSame('0.02200000', $second->base_unit_cost);
        $this->assertFalse($first->fresh()->is_current);
        $this->assertTrue($second->fresh()->is_current);
        $this->assertDatabaseCount('ingredient_prices', 2);

        $product = Product::query()->create(['name' => 'Produto', 'stock_unit' => 'un', 'active' => true]);
        app(ProductPriceService::class)->record($product, '10', null, 'agent', 'product-price-1', '2026-08-20');
        app(ProductPriceService::class)->record($product, '12', null, 'agent', 'product-price-2', '2026-08-24');
        $this->assertDatabaseCount('product_prices', 2);
        $this->assertDatabaseHas('product_prices', ['price' => '10.0000', 'is_current' => false]);
        $this->assertDatabaseHas('product_prices', ['price' => '12.0000', 'is_current' => true]);
    }

    private function pricedIngredient(string $name, Supplier $supplier): Ingredient
    {
        $ingredient = Ingredient::query()->create(['name' => $name, 'base_unit' => 'g', 'active' => true]);
        app(IngredientPriceService::class)->record($ingredient, ['supplier_id' => $supplier->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'price_paid' => '10', 'effective_date' => '2026-08-24']);

        return $ingredient;
    }
}
