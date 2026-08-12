<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

class StockBalanceService
{
    public function balance(Product|int $product, Location|int $location, ?DateTimeInterface $throughDate = null): string
    {
        $query = $this->movementQuery($product, $location);

        if ($throughDate !== null) {
            $query->whereDate('operation_date', '<=', $throughDate);
        }

        $sum = $query->sum('quantity_delta');

        return (string) BigDecimal::of((string) $sum)->toScale(6, RoundingMode::HalfUp);
    }

    /** @return Builder<StockMovement> */
    private function movementQuery(Product|int $product, Location|int $location): Builder
    {
        return StockMovement::query()
            ->where('product_id', $product instanceof Product ? $product->getKey() : $product)
            ->where('location_id', $location instanceof Location ? $location->getKey() : $location);
    }
}
