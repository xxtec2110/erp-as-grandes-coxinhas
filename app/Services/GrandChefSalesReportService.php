<?php

namespace App\Services;

use App\Models\PdvConnection;
use App\Models\PdvProductMapping;
use App\Pdv\Data\ExternalSaleData;
use App\Pdv\Data\ExternalSalePaymentData;
use App\Pdv\IntegrationNotConfiguredException;
use App\Pdv\PdvProviderManager;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use RuntimeException;

class GrandChefSalesReportService
{
    public function __construct(
        private PdvProviderManager $providers,
        private PdvConnectionAccessService $access,
    ) {}

    public function report(PdvConnection $connection, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->access->assertOperationalScope($connection);
        if (! $connection->enabled) {
            throw new IntegrationNotConfiguredException('A conexão GrandChef está inativa.');
        }
        $provider = $this->providers->for($connection);
        $cursor = null;
        $seenCursors = [];
        $sales = [];
        $reportedTotal = null;
        $pages = 0;
        $maxPages = max(1, (int) config('pdv.grandchef.max_report_pages', 20));
        $truncated = false;

        do {
            $page = $provider->fetchSales($connection, $cursor, $from, $to);
            $pages++;
            $reportedTotal ??= $page->reportedTotal;
            array_push($sales, ...$page->items);
            $cursor = $page->nextCursor;

            if ($cursor !== null) {
                $signature = hash('sha256', json_encode($cursor, JSON_THROW_ON_ERROR));
                if (isset($seenCursors[$signature])) {
                    throw new RuntimeException('O GrandChef repetiu o cursor de paginação. A consulta foi interrompida com segurança.');
                }
                $seenCursors[$signature] = true;
            }

            if ($cursor !== null && $pages >= $maxPages) {
                $truncated = true;
                break;
            }
        } while ($cursor !== null);

        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $periodStart = $from->setTimezone($timezone)->startOfDay();
        $periodEnd = $to->setTimezone($timezone)->endOfDay();
        $sales = collect($sales)
            ->filter(fn (ExternalSaleData $sale): bool => $sale->closedAt->setTimezone($timezone)->betweenIncluded($periodStart, $periodEnd))
            ->unique(fn (ExternalSaleData $sale): string => $sale->externalSaleId)
            ->values()
            ->all();

        return $this->summarize($connection, $sales, [
            'pages' => $pages,
            'reported_total' => $reportedTotal,
            'complete' => ! $truncated && ($reportedTotal === null || $reportedTotal === count($sales)),
            'truncated' => $truncated,
        ]);
    }

    public function sale(PdvConnection $connection, string $externalSaleId): ?ExternalSaleData
    {
        $this->access->assertOperationalScope($connection);
        if (! $connection->enabled) {
            throw new IntegrationNotConfiguredException('A conexão GrandChef está inativa.');
        }

        return $this->providers->for($connection)->fetchSale($connection, $externalSaleId);
    }

    /** @param array<int, ExternalSaleData> $sales */
    public function summarize(PdvConnection $connection, array $sales, array $pagination = []): array
    {
        $gross = BigDecimal::zero();
        $discounts = BigDecimal::zero();
        $total = BigDecimal::zero();
        $paid = BigDecimal::zero();
        $itemQuantity = BigDecimal::zero();
        $items = [];
        $payments = [];

        foreach ($sales as $sale) {
            $gross = $gross->plus($sale->grossAmount);
            $discounts = $discounts->plus($sale->discountAmount);
            $total = $total->plus($sale->netAmount);
            $paid = $paid->plus($sale->paidAmount ?? $this->paymentTotal($sale->payments));

            foreach ($sale->items as $item) {
                if ($item->cancelled) {
                    continue;
                }
                $itemQuantity = $itemQuantity->plus($item->quantity);
                $key = $item->externalProductId ?: ($item->sku ?: 'name:'.mb_strtolower($item->name));
                $items[$key] ??= [
                    'external_product_id' => $item->externalProductId,
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'quantity' => BigDecimal::zero(),
                    'total' => BigDecimal::zero(),
                    'unit_prices' => [],
                ];
                $items[$key]['quantity'] = $items[$key]['quantity']->plus($item->quantity);
                $items[$key]['total'] = $items[$key]['total']->plus($item->total);
                $items[$key]['unit_prices'][(string) BigDecimal::of($item->unitPrice)->toScale(8, RoundingMode::HalfUp)] = true;
            }

            foreach ($sale->payments as $payment) {
                $key = $payment->methodCode ?: ($payment->methodName ?: 'não informado');
                $payments[$key] ??= [
                    'method_code' => $payment->methodCode,
                    'method_name' => $payment->methodName,
                    'type' => $payment->type,
                    'occurrences' => 0,
                    'amount' => BigDecimal::zero(),
                ];
                $payments[$key]['occurrences']++;
                $payments[$key]['amount'] = $payments[$key]['amount']->plus($payment->amount);
            }
        }

        $confirmedMappings = PdvProductMapping::query()
            ->whereBelongsTo($connection, 'connection')
            ->where('status', 'confirmed')
            ->whereNotNull('product_id')
            ->with('product.category')
            ->get();
        $confirmedExternalIds = $confirmedMappings->pluck('external_product_id')->flip();
        $mappedCoxinhas = $confirmedMappings
            ->filter(fn (PdvProductMapping $mapping): bool => mb_strtolower(trim((string) $mapping->product?->category?->name)) === 'coxinhas')
            ->pluck('external_product_id')
            ->flip();
        $confirmedCoxinhas = BigDecimal::zero();
        $unmappedItems = false;

        foreach ($items as $item) {
            if ($item['external_product_id'] !== null && $mappedCoxinhas->has($item['external_product_id'])) {
                $confirmedCoxinhas = $confirmedCoxinhas->plus($item['quantity']);
            }

            if ($item['external_product_id'] === null || ! $confirmedExternalIds->has($item['external_product_id'])) {
                $unmappedItems = true;
            }
        }

        $orders = count($sales);

        return [
            'summary' => [
                'orders' => $orders,
                'items_quantity' => $this->quantity($itemQuantity),
                'gross_amount' => $this->money($gross),
                'discount_amount' => $this->money($discounts),
                'total_amount' => $this->money($total),
                'paid_amount' => $this->money($paid),
                'average_ticket' => $orders === 0 ? '0.00' : (string) $total->dividedBy($orders, 2, RoundingMode::HalfUp),
                'confirmed_coxinha_quantity' => $this->quantity($confirmedCoxinhas),
                'coxinha_count_complete' => ! $unmappedItems,
            ],
            'items' => collect($items)->map(function (array $item): array {
                $prices = array_keys($item['unit_prices']);

                return array_merge($item, [
                    'quantity' => $this->quantity($item['quantity']),
                    'total' => $this->money($item['total']),
                    'unit_price' => count($prices) === 1 ? $this->money(BigDecimal::of($prices[0])) : null,
                ]);
            })->sort(fn (array $left, array $right): int => BigDecimal::of($right['total'])->compareTo($left['total']))->values()->all(),
            'payments' => collect($payments)->map(fn (array $payment): array => array_merge($payment, ['amount' => $this->money($payment['amount'])]))->values()->all(),
            'orders' => $sales,
            'pagination' => array_merge(['pages' => 0, 'reported_total' => null, 'complete' => true, 'truncated' => false], $pagination),
        ];
    }

    /** @param array<int, ExternalSalePaymentData> $payments */
    private function paymentTotal(array $payments): BigDecimal
    {
        return collect($payments)->reduce(fn (BigDecimal $total, ExternalSalePaymentData $payment): BigDecimal => $total->plus($payment->amount), BigDecimal::zero());
    }

    private function money(BigDecimal $value): string
    {
        return (string) $value->toScale(2, RoundingMode::HalfUp);
    }

    private function quantity(BigDecimal $value): string
    {
        return (string) $value->toScale(6, RoundingMode::HalfUp);
    }
}
