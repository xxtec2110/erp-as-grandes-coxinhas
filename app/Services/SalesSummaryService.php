<?php

namespace App\Services;

use App\Models\Location;
use App\Models\ProductSale;

class SalesSummaryService
{
    /** @return array<string, mixed> */
    public function summarize(Location $location, string $start, string $end): array
    {
        $query = ProductSale::query()->whereBelongsTo($location)->whereBetween('operation_date', [$start, $end]);

        return ['quantity' => (string) (clone $query)->sum('quantity'), 'revenue' => (string) (clone $query)->sum('total_amount'), 'fees' => (string) (clone $query)->sum('fee_amount_snapshot'), 'net' => (string) (clone $query)->sum('net_amount'), 'by_product' => (clone $query)->join('products', 'products.id', '=', 'product_sales.product_id')->selectRaw('products.name, products.stock_unit, SUM(product_sales.quantity) quantity, SUM(product_sales.total_amount) revenue')->groupBy('products.id', 'products.name', 'products.stock_unit')->orderByDesc('revenue')->get(), 'by_category' => (clone $query)->join('products', 'products.id', '=', 'product_sales.product_id')->leftJoin('product_categories', 'product_categories.id', '=', 'products.product_category_id')->selectRaw("COALESCE(product_categories.name, 'Sem categoria') category, SUM(product_sales.quantity) quantity, SUM(product_sales.total_amount) revenue")->groupBy('product_categories.id', 'product_categories.name')->orderByDesc('revenue')->get()];
    }
}
