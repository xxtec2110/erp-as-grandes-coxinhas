<?php

namespace App\Services;

use App\Models\Location;
use App\Models\ProductSale;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class SalesSummaryService
{
    /** @return array<string, mixed> */
    public function summarize(Location $location, string $start, string $end, ?string $paymentMethod = null): array
    {
        $query = ProductSale::query()->whereBelongsTo($location)->whereDate('operation_date', '>=', $start)->whereDate('operation_date', '<=', $end)->whereNull('cancelled_at')
            ->when($paymentMethod, fn ($query) => $query->where(fn ($query) => $query->where('payment_method', $paymentMethod)->orWhereHas('paymentAllocations.payment', fn ($query) => $query->where('payment_method', $paymentMethod))));

        $revenue = (string) (clone $query)->sum('total_amount');
        $cost = (string) (clone $query)->sum('total_cost_snapshot');
        $discounts = (string) (clone $query)->sum('discount_amount_snapshot');
        $salesCount = (clone $query)->whereNull('product_sale_order_id')->count()
            + (clone $query)->whereNotNull('product_sale_order_id')->distinct()->count('product_sale_order_id');
        $missingCostCount = (clone $query)->whereNull('unit_cost_snapshot')->count();
        $profit = $missingCostCount > 0 ? null : (string) BigDecimal::of($revenue)->minus($cost)->toScale(2, RoundingMode::HalfUp);
        $margin = $profit === null || BigDecimal::of($revenue)->isZero() ? null : (string) BigDecimal::of($profit)->multipliedBy(100)->dividedBy($revenue, 4, RoundingMode::HalfUp);
        $averageTicket = $salesCount === 0
            ? '0.00'
            : (string) BigDecimal::of($revenue)->dividedBy($salesCount, 2, RoundingMode::HalfUp);

        return ['sales_count' => $salesCount, 'quantity' => (string) (clone $query)->sum('quantity'), 'revenue' => $revenue, 'discounts' => $discounts, 'average_ticket' => $averageTicket, 'fees' => (string) (clone $query)->sum('fee_amount_snapshot'), 'net' => (string) (clone $query)->sum('net_amount'), 'cost_of_goods' => $cost, 'gross_profit' => $profit, 'gross_margin_percentage' => $margin, 'missing_cost_count' => $missingCostCount, 'by_product' => (clone $query)->join('products', 'products.id', '=', 'product_sales.product_id')->selectRaw('products.name, products.stock_unit, SUM(product_sales.quantity) quantity, SUM(product_sales.total_amount) revenue, SUM(product_sales.total_cost_snapshot) cost')->groupBy('products.id', 'products.name', 'products.stock_unit')->orderByDesc('revenue')->get(), 'by_category' => (clone $query)->join('products', 'products.id', '=', 'product_sales.product_id')->leftJoin('product_categories', 'product_categories.id', '=', 'products.product_category_id')->selectRaw("COALESCE(product_categories.name, 'Sem categoria') category, SUM(product_sales.quantity) quantity, SUM(product_sales.total_amount) revenue")->groupBy('product_categories.id', 'product_categories.name')->orderByDesc('revenue')->get()];
    }
}
