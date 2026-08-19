<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\Location;
use App\Models\Preparation;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;

class OperationalReadinessService
{
    public function __construct(
        private PreparationCostService $preparationCosts,
        private ProductRecipeCostService $recipeCosts,
    ) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $ingredients = Ingredient::query()->where('active', true)->with('currentPrice')->get();
        $suppliers = Supplier::query()->where('active', true)->count();
        $preparations = Preparation::query()->where('active', true)->get();
        $completePreparations = $preparations->filter(
            fn (Preparation $preparation): bool => $this->preparationCosts->calculate($preparation)['is_complete'],
        )->count();
        $products = Product::query()->where('active', true)->with(['recipe.ingredients', 'recipe.preparations'])->get();
        $recipes = $products->filter(fn (Product $product): bool => $product->recipe !== null);
        $completeCosts = $recipes->filter(
            fn (Product $product): bool => $this->recipeCosts->calculate($product->recipe)['unit_cost'] !== null,
        )->count();
        $locations = Location::query()->where('active', true)->orderBy('name')->get();
        $openingByLocation = $locations->map(function (Location $location): array {
            $products = StockMovement::query()
                ->where('location_id', $location->id)
                ->where('type', StockMovementType::OpeningBalance->value)
                ->distinct('product_id')
                ->count('product_id');

            return ['location' => $location, 'products' => $products, 'started' => $products > 0];
        });
        $stores = $locations->where('type', Location::TYPE_STORE)->values();
        $targetsConfigured = $stores->whereNotNull('daily_sales_target')->count();

        return [
            'ingredients' => ['total' => $ingredients->count(), 'with_price' => $ingredients->whereNotNull('currentPrice')->count()],
            'suppliers' => ['total' => $suppliers],
            'preparations' => ['total' => $preparations->count(), 'complete' => $completePreparations],
            'products' => ['total' => $products->count(), 'with_recipe' => $recipes->count(), 'with_cost' => $completeCosts],
            'opening_stock' => ['locations' => $openingByLocation, 'started' => $openingByLocation->where('started', true)->count(), 'total' => $locations->count()],
            'targets' => ['configured' => $targetsConfigured, 'total' => $stores->count()],
        ];
    }
}
