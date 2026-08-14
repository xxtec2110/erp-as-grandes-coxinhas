<?php

namespace App\Services;

use App\Models\Location;
use App\Models\ProductSale;

class ProductSalesRankingService
{
    public function forPeriod(string $start, string $end, ?Location $location = null)
    {
        return ProductSale::query()->join('products', 'products.id', '=', 'product_sales.product_id')->whereNull('product_sales.cancelled_at')->whereBetween('operation_date', [$start, $end])->when($location, fn ($q) => $q->where('location_id', $location->id))->selectRaw('products.id, products.name, SUM(product_sales.quantity) quantity, SUM(product_sales.total_amount) revenue')->groupBy('products.id', 'products.name')->orderByDesc('quantity')->get();
    }
}
