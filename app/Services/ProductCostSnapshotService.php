<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductCostSnapshot;
use App\Models\ProductRecipe;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ProductCostSnapshotService
{
    public function __construct(private ProductRecipeCostService $costs) {}

    public function current(Product $product, string $sourceType = 'calculated', ?string $sourceId = null, array $context = []): ?ProductCostSnapshot
    {
        $recipe = $product->recipe()->with(['ingredients.ingredient.currentPrice', 'preparations.preparation'])->first();
        if ($recipe === null) {
            return null;
        }
        $calculation = $this->costs->calculate($recipe);
        if (! $calculation['is_complete']) {
            return null;
        }

        return $this->store($product, $recipe, $calculation, now(), $sourceType, $sourceId, $context);
    }

    public function at(Product $product, string|Carbon $effectiveAt): ?ProductCostSnapshot
    {
        return $product->costSnapshots()->where('effective_at', '<=', Carbon::parse($effectiveAt))->latest('effective_at')->first();
    }

    public function snapshotAffectedByIngredient(Ingredient $ingredient, string $sourceType, ?string $sourceId = null): Collection
    {
        $direct = ProductRecipe::query()->whereHas('ingredients', fn ($query) => $query->where('ingredient_id', $ingredient->id))->pluck('product_id');
        $throughPreparation = ProductRecipe::query()->whereHas('preparations.preparation.preparationIngredients', fn ($query) => $query->where('ingredient_id', $ingredient->id))->pluck('product_id');

        return Product::query()->whereIn('id', $direct->merge($throughPreparation)->unique())->get()
            ->map(fn (Product $product) => $this->current($product, $sourceType, $sourceId, ['ingredient_id' => $ingredient->id]))
            ->filter()->values();
    }

    private function store(Product $product, ProductRecipe $recipe, array $calculation, Carbon $effectiveAt, string $sourceType, ?string $sourceId, array $context): ProductCostSnapshot
    {
        $recipe->load(['ingredients.ingredient.currentPrice', 'preparations.preparation']);
        $components = [
            'ingredients_cost' => $calculation['ingredients_cost'],
            'preparations_cost' => $calculation['preparations_cost'],
            'packaging_cost' => $calculation['packaging_cost'],
            'direct_cost' => $calculation['direct_cost'],
            'yield_quantity' => $recipe->yield_quantity,
            'technical_loss_percentage' => $recipe->technical_loss_percentage,
            'ingredient_prices' => $recipe->ingredients->map(fn ($item) => ['ingredient_id' => $item->ingredient_id, 'quantity' => $item->quantity, 'unit' => $item->unit, 'price_id' => $item->ingredient->currentPrice?->id, 'base_unit_cost' => $item->ingredient->currentPrice?->base_unit_cost])->values()->all(),
            'preparations' => $recipe->preparations->map(fn ($item) => ['preparation_id' => $item->preparation_id, 'quantity' => $item->quantity, 'unit' => $item->unit, 'updated_at' => $item->preparation->updated_at?->toISOString()])->values()->all(),
        ];
        $signature = hash('sha256', json_encode($components, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $sellingPrice = $product->currentPrice?->price;
        $profit = $sellingPrice === null ? null : BigDecimal::of($sellingPrice)->minus($calculation['unit_cost']);
        $margin = $sellingPrice === null || BigDecimal::of($sellingPrice)->isZero() ? null : $profit->multipliedBy(100)->dividedBy($sellingPrice, 4, RoundingMode::HalfUp);
        $key = 'product-cost:'.hash('sha256', implode('|', [$product->id, $signature, $sourceType, $sourceId ?? 'current']));

        return ProductCostSnapshot::query()->firstOrCreate(['idempotency_key' => $key], [
            'product_id' => $product->id,
            'product_recipe_id' => $recipe->id,
            'effective_at' => $effectiveAt,
            'unit_cost' => $calculation['unit_cost'],
            'selling_price' => $sellingPrice,
            'gross_profit' => $profit?->toScale(4, RoundingMode::HalfUp),
            'gross_margin_percentage' => $margin,
            'recipe_signature' => $signature,
            'cost_method' => 'replacement_cost',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'components' => $components,
            'context' => $context,
        ]);
    }
}
