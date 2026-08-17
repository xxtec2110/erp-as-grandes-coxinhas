<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientPrice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class IngredientPriceAnalyticsService
{
    public function compareToCurrent(Ingredient $ingredient, ?string $newBaseUnitCost): array
    {
        $ingredient->loadMissing('currentPrice.supplier');
        if ($ingredient->currentPrice === null) {
            return ['status' => 'first_price', 'current' => null, 'difference' => null, 'variation_percentage' => null];
        }
        if ($newBaseUnitCost === null) {
            return ['status' => 'unavailable', 'current' => $ingredient->currentPrice, 'difference' => null, 'variation_percentage' => null];
        }

        $current = BigDecimal::of($ingredient->currentPrice->base_unit_cost);
        $difference = BigDecimal::of($newBaseUnitCost)->minus($current)->toScale(8, RoundingMode::HalfUp);
        $variation = $current->isZero() ? null : $difference->multipliedBy(100)->dividedBy($current, 4, RoundingMode::HalfUp);

        return ['status' => 'compared', 'current' => $ingredient->currentPrice, 'difference' => (string) $difference, 'variation_percentage' => $variation === null ? null : (string) $variation];
    }

    public function summary(Ingredient $ingredient, int $days = 30): array
    {
        $prices = $this->actualPurchases($ingredient, $days);
        if ($prices->isEmpty()) {
            return ['count' => 0, 'minimum' => null, 'maximum' => null, 'average' => null, 'weighted_average' => null, 'variation_percentage' => null];
        }

        $minimum = $prices->min(fn (IngredientPrice $price) => $price->base_unit_cost);
        $maximum = $prices->max(fn (IngredientPrice $price) => $price->base_unit_cost);
        $totalCost = BigDecimal::zero();
        $totalQuantity = BigDecimal::zero();
        $sum = BigDecimal::zero();
        foreach ($prices as $price) {
            $quantity = BigDecimal::of($price->normalized_quantity);
            $sum = $sum->plus($price->base_unit_cost);
            $totalCost = $totalCost->plus(BigDecimal::of($price->base_unit_cost)->multipliedBy($quantity));
            $totalQuantity = $totalQuantity->plus($quantity);
        }
        $first = $prices->sortBy(fn (IngredientPrice $price) => $price->purchase_date?->toDateString() ?? $price->effective_date->toDateString())->first();
        $last = $prices->sortByDesc(fn (IngredientPrice $price) => $price->purchase_date?->toDateString() ?? $price->effective_date->toDateString())->first();
        $variation = BigDecimal::of($first->base_unit_cost)->isZero() ? null : BigDecimal::of($last->base_unit_cost)->minus($first->base_unit_cost)->multipliedBy(100)->dividedBy($first->base_unit_cost, 4, RoundingMode::HalfUp);

        return [
            'count' => $prices->count(),
            'minimum' => (string) BigDecimal::of($minimum)->toScale(8),
            'maximum' => (string) BigDecimal::of($maximum)->toScale(8),
            'average' => (string) $sum->dividedBy((string) $prices->count(), 8, RoundingMode::HalfUp),
            'weighted_average' => $totalQuantity->isZero() ? null : (string) $totalCost->dividedBy($totalQuantity, 8, RoundingMode::HalfUp),
            'variation_percentage' => $variation === null ? null : (string) $variation,
        ];
    }

    public function suppliers(Ingredient $ingredient, int $days = 30): Collection
    {
        return $this->actualPurchases($ingredient, $days)->groupBy('supplier_id')->map(function (Collection $prices): array {
            /** @var IngredientPrice $latest */
            $latest = $prices->sortByDesc(fn (IngredientPrice $price) => $price->purchase_date?->toDateString() ?? $price->effective_date->toDateString())->first();
            $sum = BigDecimal::zero();
            $totalCost = BigDecimal::zero();
            $totalQuantity = BigDecimal::zero();
            foreach ($prices as $price) {
                $quantity = BigDecimal::of($price->normalized_quantity);
                $sum = $sum->plus($price->base_unit_cost);
                $totalCost = $totalCost->plus(BigDecimal::of($price->base_unit_cost)->multipliedBy($quantity));
                $totalQuantity = $totalQuantity->plus($quantity);
            }

            return [
                'supplier' => $latest->supplier,
                'latest' => $latest,
                'count' => $prices->count(),
                'minimum' => $prices->min('base_unit_cost'),
                'maximum' => $prices->max('base_unit_cost'),
                'average' => (string) $sum->dividedBy((string) $prices->count(), 8, RoundingMode::HalfUp),
                'weighted_average' => $totalQuantity->isZero() ? null : (string) $totalCost->dividedBy($totalQuantity, 8, RoundingMode::HalfUp),
                'normalized_quantity' => (string) $totalQuantity->toScale(6, RoundingMode::HalfUp),
            ];
        })->sortBy(fn (array $row) => $row['latest']->base_unit_cost)->values();
    }

    public function variationReport(Carbon $start, Carbon $end): Collection
    {
        return IngredientPrice::query()
            ->with(['ingredient', 'supplier'])
            ->whereIn('source_type', ['manual_price', 'purchase', 'receipt', 'invoice'])
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('purchase_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhere(function ($legacy) use ($start, $end): void {
                        $legacy->whereNull('purchase_date')->whereBetween('effective_date', [$start->toDateString(), $end->toDateString()]);
                    });
            })
            ->orderByRaw('COALESCE(purchase_date, effective_date)')
            ->orderBy('id')
            ->get()
            ->groupBy('ingredient_id')
            ->map(function (Collection $prices): array {
                /** @var IngredientPrice $first */
                $first = $prices->first();
                /** @var IngredientPrice $last */
                $last = $prices->last();
                $difference = BigDecimal::of($last->base_unit_cost)->minus($first->base_unit_cost)->toScale(8, RoundingMode::HalfUp);
                $variation = BigDecimal::of($first->base_unit_cost)->isZero()
                    ? null
                    : $difference->multipliedBy(100)->dividedBy($first->base_unit_cost, 4, RoundingMode::HalfUp);

                return [
                    'ingredient' => $last->ingredient,
                    'initial' => $first,
                    'final' => $last,
                    'difference' => (string) $difference,
                    'variation_percentage' => $variation === null ? null : (string) $variation,
                    'latest_supplier' => $last->supplier,
                    'purchases_count' => $prices->count(),
                ];
            })
            ->sortBy(fn (array $row) => $row['ingredient']->name)
            ->values();
    }

    private function actualPurchases(Ingredient $ingredient, int $days): Collection
    {
        return $ingredient->prices()->with('supplier')
            ->whereIn('source_type', ['manual_price', 'purchase', 'receipt', 'invoice'])
            ->where(fn ($query) => $query->whereDate('purchase_date', '>=', now()->subDays($days)->toDateString())->orWhere(fn ($legacy) => $legacy->whereNull('purchase_date')->whereDate('effective_date', '>=', now()->subDays($days)->toDateString())))
            ->get();
    }
}
