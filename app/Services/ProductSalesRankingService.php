<?php

namespace App\Services;

use App\Models\Location;
use App\Models\ProductSale;

class ProductSalesRankingService
{
    public function forPeriod(string $start, string $end, ?Location $location = null, ?int $productId = null)
    {
        return ProductSale::query()->join('products', 'products.id', '=', 'product_sales.product_id')->whereNull('product_sales.cancelled_at')->whereDate('product_sales.operation_date', '>=', $start)->whereDate('product_sales.operation_date', '<=', $end)->when($location, fn ($q) => $q->where('product_sales.location_id', $location->id))->when($productId, fn ($q) => $q->where('product_sales.product_id', $productId))->selectRaw('products.id, products.name, SUM(product_sales.quantity) quantity, SUM(product_sales.total_amount) revenue')->groupBy('products.id', 'products.name')->orderByDesc('quantity')->get();
    }
}
