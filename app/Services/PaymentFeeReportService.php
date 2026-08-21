<?php

namespace App\Services;

use App\Models\Location;
use App\Models\ProductSale;
use App\Models\ProductSalePayment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

class PaymentFeeReportService
{
    /** @return array<string, mixed> */
    public function summarize(Location $location, string $start, string $end): array
    {
        $manual = ProductSale::query()
            ->whereBelongsTo($location)
            ->whereDate('operation_date', '>=', $start)
            ->whereDate('operation_date', '<=', $end)
            ->whereNull('product_sale_order_id')
            ->whereNull('cancelled_at');
        $official = ProductSalePayment::query()
            ->join('product_sale_orders', 'product_sale_orders.id', '=', 'product_sale_payments.product_sale_order_id')
            ->where('product_sale_orders.location_id', $location->id)
            ->whereDate('product_sale_orders.operation_date', '>=', $start)
            ->whereDate('product_sale_orders.operation_date', '<=', $end);

        $gross = BigDecimal::of((string) (clone $manual)->sum('gross_amount'))->plus((string) (clone $official)->sum('amount'));
        $fees = BigDecimal::of((string) (clone $manual)->sum('fee_amount_snapshot'))->plus((string) (clone $official)->sum('fee_amount'));
        $net = BigDecimal::of((string) (clone $manual)->sum('net_amount'))->plus((string) (clone $official)->sum('net_amount'));

        $manualAcquirer = (clone $manual)->leftJoin('acquirers', 'acquirers.id', '=', 'product_sales.acquirer_id')
            ->selectRaw("COALESCE(acquirers.name, 'Sem adquirente') name, SUM(gross_amount) gross, SUM(fee_amount_snapshot) fees, SUM(net_amount) net")
            ->groupBy('acquirers.id', 'acquirers.name')->get();
        $officialAcquirer = (clone $official)->leftJoin('acquirers', 'acquirers.id', '=', 'product_sale_payments.acquirer_id')
            ->selectRaw("COALESCE(acquirers.name, 'Sem adquirente') name, SUM(product_sale_payments.amount) gross, SUM(product_sale_payments.fee_amount) fees, SUM(product_sale_payments.net_amount) net")
            ->groupBy('acquirers.id', 'acquirers.name')->get();
        $manualBrand = (clone $manual)->leftJoin('card_brands', 'card_brands.id', '=', 'product_sales.card_brand_id')
            ->selectRaw("COALESCE(card_brands.name, 'Sem bandeira') name, SUM(gross_amount) gross, SUM(fee_amount_snapshot) fees, SUM(net_amount) net")
            ->groupBy('card_brands.id', 'card_brands.name')->get();
        $officialBrand = (clone $official)->leftJoin('card_brands', 'card_brands.id', '=', 'product_sale_payments.card_brand_id')
            ->selectRaw("COALESCE(card_brands.name, 'Sem bandeira') name, SUM(product_sale_payments.amount) gross, SUM(product_sale_payments.fee_amount) fees, SUM(product_sale_payments.net_amount) net")
            ->groupBy('card_brands.id', 'card_brands.name')->get();
        $manualMethod = (clone $manual)->selectRaw('payment_method, SUM(gross_amount) gross, SUM(fee_amount_snapshot) fees, SUM(net_amount) net')->groupBy('payment_method')->get();
        $officialMethod = (clone $official)->selectRaw('product_sale_payments.payment_method, SUM(product_sale_payments.amount) gross, SUM(product_sale_payments.fee_amount) fees, SUM(product_sale_payments.net_amount) net')->groupBy('product_sale_payments.payment_method')->get();

        return [
            'gross' => $this->money($gross),
            'fees' => $this->money($fees),
            'net' => $this->money($net),
            'by_acquirer' => $this->mergeGroups($manualAcquirer, $officialAcquirer, 'name'),
            'by_brand' => $this->mergeGroups($manualBrand, $officialBrand, 'name'),
            'by_method' => $this->mergeGroups($manualMethod, $officialMethod, 'payment_method'),
        ];
    }

    /** @param Collection<int,object> $manual @param Collection<int,object> $official */
    private function mergeGroups(Collection $manual, Collection $official, string $key): Collection
    {
        return $manual->concat($official)->groupBy($key)->map(function (Collection $rows) use ($key): object {
            $gross = $rows->reduce(fn (BigDecimal $sum, object $row): BigDecimal => $sum->plus((string) $row->gross), BigDecimal::zero());
            $fees = $rows->reduce(fn (BigDecimal $sum, object $row): BigDecimal => $sum->plus((string) $row->fees), BigDecimal::zero());
            $net = $rows->reduce(fn (BigDecimal $sum, object $row): BigDecimal => $sum->plus((string) $row->net), BigDecimal::zero());

            return (object) [$key => $rows->first()->{$key}, 'gross' => $this->money($gross), 'fees' => $this->money($fees), 'net' => $this->money($net)];
        })->values();
    }

    private function money(BigDecimal $amount): string
    {
        $formatted = (string) $amount->toScale(2, RoundingMode::HalfUp);

        $normalized = rtrim(rtrim($formatted, '0'), '.');

        return $normalized === '' || $normalized === '-0' ? '0' : $normalized;
    }
}
