<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientPrice;
use Illuminate\Support\Facades\DB;

class IngredientPriceService
{
    public function __construct(private UnitConversionService $unitConversion) {}

    /** @param array<string, mixed> $data */
    public function record(Ingredient $ingredient, array $data): IngredientPrice
    {
        return DB::transaction(function () use ($ingredient, $data): IngredientPrice {
            Ingredient::query()->whereKey($ingredient->getKey())->lockForUpdate()->firstOrFail();

            $normalizedQuantity = $this->unitConversion->normalize(
                (string) $data['purchase_quantity'],
                (string) $data['purchase_unit'],
                $ingredient->base_unit,
            );

            $baseUnitCost = $this->unitConversion->calculateBaseUnitCost(
                (string) $data['price_paid'],
                $normalizedQuantity,
            );

            $hasCurrentPrice = $ingredient->prices()->where('is_current', true)->exists();
            $isCurrent = (bool) ($data['is_current'] ?? false) || ! $hasCurrentPrice;

            if ($isCurrent) {
                $ingredient->prices()->where('is_current', true)->update(['is_current' => false]);
            }

            return $ingredient->prices()->create([
                ...$data,
                'normalized_quantity' => $normalizedQuantity,
                'base_unit_cost' => $baseUnitCost,
                'is_current' => $isCurrent,
            ]);
        });
    }
}
