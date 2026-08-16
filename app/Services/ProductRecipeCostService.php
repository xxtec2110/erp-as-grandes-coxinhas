<?php

namespace App\Services;

use App\Models\ProductRecipe;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class ProductRecipeCostService
{
    public function __construct(private UnitConversionService $units, private PreparationCostService $preparationCosts) {}

    public function calculate(ProductRecipe $recipe): array
    {
        $recipe->load(['product.currentPrice', 'ingredients.ingredient.currentPrice', 'preparations.preparation']);
        $ingredients = BigDecimal::zero();
        $preparations = BigDecimal::zero();
        $missing = 0;
        foreach ($recipe->ingredients as $item) {
            $price = $item->ingredient->currentPrice;
            if ($price === null) {
                $missing++;

                continue;
            }
            $ingredients = $ingredients->plus(BigDecimal::of($price->base_unit_cost)->multipliedBy($this->units->normalize($item->quantity, $item->unit, $item->ingredient->base_unit)));
        }
        foreach ($recipe->preparations as $item) {
            $cost = $this->preparationCosts->calculate($item->preparation);
            $unitCost = $cost['unit_costs']['base_unit_cost'] ?? null;
            if ($unitCost === null || ! $this->units->areCompatible($item->unit, $cost['unit_costs']['base_unit'] ?? '')) {
                $missing++;

                continue;
            }
            $preparations = $preparations->plus(BigDecimal::of($unitCost)->multipliedBy($this->units->normalizeToBase($item->quantity, $item->unit)));
        }
        $direct = $ingredients->plus($preparations)->plus($recipe->packaging_cost);
        if (BigDecimal::of($recipe->technical_loss_percentage)->isPositive()) {
            $direct = $direct->dividedBy(BigDecimal::one()->minus(BigDecimal::of($recipe->technical_loss_percentage)->dividedBy(100, 12, RoundingMode::HalfUp)), 12, RoundingMode::HalfUp);
        }
        $unit = $direct->dividedBy($recipe->yield_quantity, 8, RoundingMode::HalfUp);
        $price = $recipe->product->currentPrice ? BigDecimal::of($recipe->product->currentPrice->price) : null;

        return ['ingredients_cost' => (string) $ingredients->toScale(8, RoundingMode::HalfUp), 'preparations_cost' => (string) $preparations->toScale(8, RoundingMode::HalfUp), 'packaging_cost' => (string) BigDecimal::of($recipe->packaging_cost)->toScale(8), 'direct_cost' => (string) $direct->toScale(8, RoundingMode::HalfUp), 'unit_cost' => (string) $unit->toScale(8, RoundingMode::HalfUp), 'gross_profit' => $price ? (string) $price->minus($unit)->toScale(4, RoundingMode::HalfUp) : null, 'gross_margin' => $price && $price->isPositive() ? (string) $price->minus($unit)->multipliedBy(100)->dividedBy($price, 4, RoundingMode::HalfUp) : null, 'missing_price_count' => $missing];
    }
}
