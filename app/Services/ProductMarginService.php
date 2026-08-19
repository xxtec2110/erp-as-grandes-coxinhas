<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCostSnapshot;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ProductMarginService
{
    public function __construct(private ProductCostSnapshotService $snapshots, private ProductRecipeCostService $costs) {}

    public function current(Product $product): array
    {
        $snapshot = $this->snapshots->current($product);

        return $this->format($product, $snapshot, $product->currentPrice?->price, true);
    }

    public function historical(Product $product, string $date): array
    {
        $at = Carbon::parse($date)->endOfDay();
        $snapshot = $this->snapshots->at($product, $at);
        $price = $product->prices()->whereDate('effective_date', '<=', $at->toDateString())->latest('effective_date')->latest('id')->value('price');

        return $this->format($product, $snapshot, $price === null ? null : (string) $price);
    }

    public function report(?string $date = null): Collection
    {
        return Product::query()->with(['recipe', 'currentPrice'])->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (Product $product) => $date === null ? $this->current($product) : $this->historical($product, $date));
    }

    private function format(Product $product, ?ProductCostSnapshot $snapshot, ?string $sellingPrice, bool $includeCurrentPartial = false): array
    {
        if ($snapshot === null) {
            $status = $product->recipe === null ? 'recipe_pending' : 'incomplete_cost';
            $partial = $includeCurrentPartial && $product->recipe !== null
                ? $this->costs->calculate($product->recipe)
                : null;

            return compact('product', 'sellingPrice', 'status') + [
                'snapshot' => null,
                'unit_cost' => null,
                'partial_unit_cost' => $partial['partial_unit_cost'] ?? null,
                'missing_components' => $partial['missing_components'] ?? [],
                'gross_profit' => null,
                'gross_margin_percentage' => null,
                'markup_percentage' => null,
            ];
        }
        $profit = $sellingPrice === null ? null : BigDecimal::of($sellingPrice)->minus($snapshot->unit_cost);
        $margin = $sellingPrice === null || BigDecimal::of($sellingPrice)->isZero() ? null : $profit->multipliedBy(100)->dividedBy($sellingPrice, 4, RoundingMode::HalfUp);
        $markup = $profit === null || BigDecimal::of($snapshot->unit_cost)->isZero() ? null : $profit->multipliedBy(100)->dividedBy($snapshot->unit_cost, 4, RoundingMode::HalfUp);

        return compact('product', 'sellingPrice', 'snapshot') + ['status' => 'complete', 'unit_cost' => $snapshot->unit_cost, 'partial_unit_cost' => null, 'missing_components' => [], 'gross_profit' => $profit?->toScale(4, RoundingMode::HalfUp), 'gross_margin_percentage' => $margin === null ? null : (string) $margin, 'markup_percentage' => $markup === null ? null : (string) $markup];
    }
}
