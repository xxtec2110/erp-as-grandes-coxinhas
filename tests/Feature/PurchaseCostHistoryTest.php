<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Location;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CreatePurchaseDocumentService;
use App\Services\IngredientPriceAnalyticsService;
use App\Services\IngredientPriceService;
use App\Services\IngredientStockService;
use App\Services\PurchaseReceiptService;
use App\Services\SupplierIngredientMappingService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PurchaseCostHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_purchase_records_normalized_history_without_moving_stock_until_receipt(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $location = Location::query()->create(['name' => 'Fábrica', 'type' => 'production', 'active' => true]);
        $supplier = Supplier::query()->create(['name' => 'Distribuidora', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Muçarela', 'base_unit' => 'g', 'active' => true]);
        $document = app(CreatePurchaseDocumentService::class)->create([
            'supplier_id' => $supplier->id, 'document_type' => 'invoice', 'document_number' => 'NF-10', 'issue_date' => '2026-08-16',
            'total_amount' => '220.00', 'location_id' => $location->id, 'idempotency_key' => 'purchase-history-1',
            'items' => [['ingredient_id' => $ingredient->id, 'description' => 'Muçarela peça', 'quantity' => '5', 'unit' => 'kg', 'unit_price' => '44', 'total_price' => '220']],
        ], $user);

        $this->assertDatabaseHas('ingredient_prices', ['purchase_document_id' => $document->id, 'ingredient_id' => $ingredient->id, 'normalized_quantity' => '5000.000000', 'base_unit_cost' => '0.04400000', 'is_current' => true]);
        $this->assertSame('0.000000', app(IngredientStockService::class)->balance($ingredient->id, $location->id));
        app(PurchaseReceiptService::class)->receive($document, '2026-08-16', $user);
        $this->assertSame('5000.000000', app(IngredientStockService::class)->balance($ingredient->id, $location->id));
        $this->assertSame(1, $ingredient->prices()->count());
        $this->assertNotNull($ingredient->currentPrice()->firstOrFail()->received_at);
    }

    public function test_quotes_are_separate_and_package_normalization_and_supplier_statistics_are_decimal_safe(): void
    {
        $user = User::factory()->create();
        $first = Supplier::query()->create(['name' => 'Fornecedor A', 'active' => true]);
        $second = Supplier::query()->create(['name' => 'Fornecedor B', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Requeijão', 'base_unit' => 'g', 'active' => true]);
        $service = app(IngredientPriceService::class);
        $service->record($ingredient, ['supplier_id' => $first->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'package_quantity' => '2', 'package_size' => '500', 'package_unit' => 'g', 'price_paid' => '40', 'effective_date' => now()->subDays(10)->toDateString(), 'source_type' => 'purchase', 'is_current' => true]);
        $service->record($ingredient, ['supplier_id' => $second->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'price_paid' => '50', 'effective_date' => now()->subDays(5)->toDateString(), 'source_type' => 'purchase', 'is_current' => true]);
        $quote = $service->record($ingredient, ['supplier_id' => $first->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'price_paid' => '30', 'effective_date' => now()->toDateString(), 'source_type' => 'quote', 'is_current' => true]);
        $summary = app(IngredientPriceAnalyticsService::class)->summary($ingredient);

        $this->assertFalse($quote->is_current);
        $this->assertSame('50.00', $ingredient->currentPrice()->firstOrFail()->price_paid);
        $this->assertSame(2, $summary['count']);
        $this->assertSame('0.04000000', $summary['minimum']);
        $this->assertSame('0.05000000', $summary['maximum']);
        $this->assertSame('0.04500000', $summary['weighted_average']);
        $comparison = app(IngredientPriceAnalyticsService::class)->suppliers($ingredient)->keyBy(fn (array $row) => $row['supplier']->id);
        $this->assertSame('0.04000000', $comparison[$first->id]['average']);
        $this->assertSame('0.04000000', $comparison[$first->id]['weighted_average']);
        $this->assertSame('1000.000000', $comparison[$first->id]['normalized_quantity']);
        $variation = app(IngredientPriceAnalyticsService::class)->compareToCurrent($ingredient, '0.055');
        $this->assertSame('0.00500000', $variation['difference']);
        $this->assertSame('10.0000', $variation['variation_percentage']);
    }

    public function test_retroactive_purchase_preserves_history_without_replacing_the_newer_current_price(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Fornecedor', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Farinha', 'base_unit' => 'g', 'active' => true]);
        $service = app(IngredientPriceService::class);

        $newer = $service->record($ingredient, ['supplier_id' => $supplier->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'price_paid' => '20', 'purchase_date' => '2026-08-16', 'source_type' => 'purchase']);
        $older = $service->record($ingredient, ['supplier_id' => $supplier->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'price_paid' => '18', 'purchase_date' => '2026-07-16', 'source_type' => 'purchase']);

        $this->assertTrue($newer->refresh()->is_current);
        $this->assertFalse($older->refresh()->is_current);
        $this->assertSame($newer->id, $ingredient->currentPrice()->firstOrFail()->id);
        $this->assertSame(2, $ingredient->prices()->count());
    }

    public function test_purchase_preserves_package_shape_and_normalizes_package_count_without_cross_dimension_conversion(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $location = Location::query()->create(['name' => 'Fábrica', 'type' => 'production', 'active' => true]);
        $supplier = Supplier::query()->create(['name' => 'Distribuidora', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Muçarela', 'base_unit' => 'g', 'active' => true]);

        $document = app(CreatePurchaseDocumentService::class)->create([
            'supplier_id' => $supplier->id, 'document_type' => 'invoice', 'document_number' => 'PKG-1', 'issue_date' => now()->toDateString(),
            'total_amount' => '1800.00', 'location_id' => $location->id, 'idempotency_key' => 'package-history-1',
            'items' => [['ingredient_id' => $ingredient->id, 'description' => 'Peças de muçarela', 'quantity' => '10', 'unit' => 'un', 'package_quantity' => '10', 'package_size' => '4', 'package_unit' => 'kg', 'unit_price' => '180', 'total_price' => '1800']],
        ], $user);

        $item = $document->items->sole();
        $this->assertSame('10.000000', $item->quantity);
        $this->assertSame('4.000000', $item->package_size);
        $this->assertSame('40000.000000', $item->normalized_quantity);
        $this->assertSame('0.04500000', $item->normalized_unit_cost);
        $this->assertSame('40000.000000', $item->priceHistory->normalized_quantity);
    }

    public function test_document_identity_and_supplier_item_mapping_are_idempotent_and_catupiry_remains_requeijao_semantics(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $location = Location::query()->create(['name' => 'Fábrica', 'type' => 'production', 'active' => true]);
        $supplier = Supplier::query()->create(['name' => 'Fornecedor', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Requeijão', 'base_unit' => 'g', 'active' => true]);
        $mapping = app(SupplierIngredientMappingService::class);
        $mapping->confirm($supplier->id, $ingredient, 'ABC', 'CREME CATUPIRY BISNAGA', $user);

        $matched = $mapping->match($supplier->id, 'ABC', 'qualquer descrição');
        $semantic = $mapping->match($supplier->id, null, 'catupiry');
        $this->assertSame($ingredient->id, $matched['ingredient_id']);
        $this->assertSame($ingredient->id, $semantic['ingredient_id']);
        $this->assertDatabaseMissing('ingredients', ['name' => 'Catupiry']);

        $payload = ['supplier_id' => $supplier->id, 'document_type' => 'invoice', 'document_number' => '100', 'series' => '1', 'issue_date' => '2026-08-16', 'total_amount' => '10', 'location_id' => $location->id, 'items' => []];
        $first = app(CreatePurchaseDocumentService::class)->create([...$payload, 'idempotency_key' => 'identity-1'], $user);
        $duplicate = app(CreatePurchaseDocumentService::class)->create([...$payload, 'total_amount' => '10.00', 'idempotency_key' => 'identity-2'], $user);
        $this->assertSame($first->id, $duplicate->id);
        $this->assertDatabaseCount('purchase_documents', 1);
    }

    public function test_variation_report_uses_real_purchase_period_and_excludes_quotes(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Fornecedor', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Frango', 'base_unit' => 'g', 'active' => true]);
        $service = app(IngredientPriceService::class);
        $service->record($ingredient, ['supplier_id' => $supplier->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'price_paid' => '20', 'purchase_date' => '2026-07-01', 'source_type' => 'purchase']);
        $service->record($ingredient, ['supplier_id' => $supplier->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'price_paid' => '25', 'purchase_date' => '2026-07-15', 'source_type' => 'purchase']);
        $service->record($ingredient, ['supplier_id' => $supplier->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'price_paid' => '99', 'purchase_date' => '2026-07-20', 'source_type' => 'quote']);

        $row = app(IngredientPriceAnalyticsService::class)->variationReport(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'))->sole();

        $this->assertSame('0.02000000', $row['initial']->base_unit_cost);
        $this->assertSame('0.02500000', $row['final']->base_unit_cost);
        $this->assertSame('0.00500000', $row['difference']);
        $this->assertSame('25.0000', $row['variation_percentage']);
        $this->assertSame(2, $row['purchases_count']);
    }
}
