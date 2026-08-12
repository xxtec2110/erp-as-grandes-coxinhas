<?php

namespace App\Services;

use App\Models\GlpPrice;
use App\Models\GlpProduct;
use Illuminate\Support\Facades\DB;

class GlpPriceService
{
    public function __construct(private UnitConversionService $unitConversion) {}

    /** @param array<string, mixed> $data */
    public function record(GlpProduct $product, array $data): GlpPrice
    {
        return DB::transaction(function () use ($product, $data): GlpPrice {
            GlpProduct::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            $unitCost = $this->unitConversion->calculateBaseUnitCost(
                (string) $data['total_price'],
                (string) $data['quantity_kg'],
            );

            $hasCurrentPrice = $product->prices()->where('is_current', true)->exists();
            $isCurrent = (bool) ($data['is_current'] ?? false) || ! $hasCurrentPrice;

            if ($isCurrent) {
                $product->prices()->where('is_current', true)->update(['is_current' => false]);
            }

            return $product->prices()->create([
                ...$data,
                'unit_cost_per_kg' => $unitCost,
                'is_current' => $isCurrent,
            ]);
        });
    }
}
