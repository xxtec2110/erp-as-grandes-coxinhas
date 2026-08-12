<?php

namespace App\Services;

use App\Models\Location;
use App\Models\ProductSale;

class PaymentFeeReportService
{
    /** @return array<string, mixed> */
    public function summarize(Location $location, string $start, string $end): array
    {
        $query = ProductSale::query()->whereBelongsTo($location)->whereBetween('operation_date', [$start, $end]);

        return ['gross' => (string) (clone $query)->sum('gross_amount'), 'fees' => (string) (clone $query)->sum('fee_amount_snapshot'), 'net' => (string) (clone $query)->sum('net_amount'), 'by_acquirer' => (clone $query)->leftJoin('acquirers', 'acquirers.id', '=', 'product_sales.acquirer_id')->selectRaw("COALESCE(acquirers.name, 'Sem adquirente') name, SUM(gross_amount) gross, SUM(fee_amount_snapshot) fees, SUM(net_amount) net")->groupBy('acquirers.id', 'acquirers.name')->orderByDesc('gross')->get(), 'by_brand' => (clone $query)->leftJoin('card_brands', 'card_brands.id', '=', 'product_sales.card_brand_id')->selectRaw("COALESCE(card_brands.name, 'Sem bandeira') name, SUM(gross_amount) gross, SUM(fee_amount_snapshot) fees, SUM(net_amount) net")->groupBy('card_brands.id', 'card_brands.name')->orderByDesc('gross')->get(), 'by_method' => (clone $query)->selectRaw('payment_method, SUM(gross_amount) gross, SUM(fee_amount_snapshot) fees, SUM(net_amount) net')->groupBy('payment_method')->get()];
    }
}
