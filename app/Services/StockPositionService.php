<?php

namespace App\Services;

use App\Enums\StockSituation;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStockPolicy;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;

class StockPositionService
{
    public function __construct(private StockBalanceService $balances) {}

    /** @return array<int, array<string, mixed>> */
    public function forLocation(Location $location, ?DateTimeInterface $throughDate = null): array
    {
        $policies = ProductStockPolicy::query()
            ->whereBelongsTo($location)
            ->get()
            ->keyBy('product_id');

        return Product::query()->where('active', true)->orderBy('name')->get()
            ->map(function (Product $product) use ($location, $throughDate, $policies): array {
                /** @var ProductStockPolicy|null $policy */
                $policy = $policies->get($product->id);
                $balance = BigDecimal::of($this->balances->balance($product, $location, $throughDate));
                $target = $policy?->active ? BigDecimal::of($policy->target_quantity) : null;
                $minimum = $policy?->active && $policy->minimum_quantity !== null
                    ? BigDecimal::of($policy->minimum_quantity)
                    : null;
                $requirement = $target !== null ? $target->minus($balance) : BigDecimal::zero();

                if ($requirement->isNegative()) {
                    $requirement = BigDecimal::zero();
                }

                $situation = match (true) {
                    $policy === null || ! $policy->active => StockSituation::NotConfigured,
                    $minimum !== null && $balance->isLessThan($minimum) => StockSituation::Critical,
                    $target !== null && $balance->isLessThan($target) => StockSituation::Attention,
                    default => StockSituation::Ok,
                };

                return [
                    'product' => $product,
                    'location' => $location,
                    'policy' => $policy,
                    'balance' => (string) $balance->toScale(6, RoundingMode::HalfUp),
                    'minimum' => $minimum !== null ? (string) $minimum->toScale(6) : null,
                    'target' => $target !== null ? (string) $target->toScale(6) : null,
                    'requirement' => (string) $requirement->toScale(6, RoundingMode::HalfUp),
                    'situation' => $situation,
                ];
            })->all();
    }
}
