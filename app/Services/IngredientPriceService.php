<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientPrice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IngredientPriceService
{
    public function __construct(private UnitConversionService $unitConversion, private ProductCostSnapshotService $snapshots) {}

    /** @param array<string, mixed> $data */
    public function record(Ingredient $ingredient, array $data): IngredientPrice
    {
        $price = DB::transaction(function () use ($ingredient, $data): IngredientPrice {
            Ingredient::query()->whereKey($ingredient->getKey())->lockForUpdate()->firstOrFail();

            if (isset($data['purchase_item_id'])) {
                $existing = IngredientPrice::query()->where('purchase_item_id', $data['purchase_item_id'])->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            $quantity = (string) $data['purchase_quantity'];
            $unit = (string) $data['purchase_unit'];
            if (isset($data['package_quantity'], $data['package_size'], $data['package_unit'])) {
                $quantity = (string) BigDecimal::of((string) $data['package_quantity'])->multipliedBy((string) $data['package_size'])->toScale(6, RoundingMode::HalfUp);
                $unit = (string) $data['package_unit'];
            }

            $normalizedQuantity = $this->unitConversion->normalize(
                $quantity,
                $unit,
                $ingredient->base_unit,
            );

            $netTotal = (string) ($data['net_total'] ?? $data['price_paid']);

            $baseUnitCost = $this->unitConversion->calculateBaseUnitCost(
                $netTotal,
                $normalizedQuantity,
            );

            $currentPrice = $ingredient->prices()->where('is_current', true)->lockForUpdate()->first();
            $sourceType = (string) ($data['source_type'] ?? 'manual_price');
            $canBeCurrent = $sourceType !== 'quote';
            $incomingDate = Carbon::parse($data['purchase_date'] ?? $data['effective_date'] ?? now());
            $currentDate = $currentPrice === null ? null : Carbon::parse($currentPrice->purchase_date ?? $currentPrice->effective_date);
            $isCurrent = $canBeCurrent && match (true) {
                $currentPrice === null => true,
                array_key_exists('is_current', $data) => (bool) $data['is_current'],
                default => $incomingDate->greaterThanOrEqualTo($currentDate),
            };

            if ($isCurrent) {
                $ingredient->prices()->where('is_current', true)->update(['is_current' => false]);
            }

            $price = $ingredient->prices()->create([
                ...$data,
                'purchase_quantity' => $quantity,
                'purchase_unit' => $unit,
                'normalized_quantity' => $normalizedQuantity,
                'normalized_unit' => $ingredient->base_unit,
                'price_paid' => $netTotal,
                'base_unit_cost' => $baseUnitCost,
                'effective_date' => $data['effective_date'] ?? $data['purchase_date'] ?? now()->toDateString(),
                'effective_at' => $data['effective_at'] ?? now(),
                'purchase_date' => $data['purchase_date'] ?? $data['effective_date'] ?? now()->toDateString(),
                'net_total' => $netTotal,
                'gross_total' => $data['gross_total'] ?? $data['price_paid'],
                'source_type' => $sourceType,
                'is_current' => false,
            ]);
            if ($isCurrent) {
                $price->update(['is_current' => true]);
            }

            return $price->refresh();
        });

        if ($price->wasRecentlyCreated && $price->is_current) {
            $this->snapshots->snapshotAffectedByIngredient($ingredient, 'ingredient_price', (string) $price->id);
        }

        return $price;
    }
}
