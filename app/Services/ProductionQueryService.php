<?php

namespace App\Services;

use App\Models\Location;
use App\Models\ProductionRecord;
use Illuminate\Database\Eloquent\Collection;

class ProductionQueryService
{
    /** @return Collection<int, ProductionRecord> */
    public function forDate(Location $location, string $date): Collection
    {
        return ProductionRecord::query()
            ->with(['product', 'location'])
            ->whereBelongsTo($location)
            ->whereDate('operation_date', $date)
            ->orderBy('status')
            ->orderBy('product_id')
            ->get();
    }
}
