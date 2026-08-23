<?php

namespace App\Services;

use App\Models\PdvConnection;
use App\Models\PdvOrder;
use App\Models\ProductSaleOrder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

class PdvSalesReconciliationService
{
    public function __construct(private PdvOrderReconciliationService $orders) {}

    /** @return array<string, mixed> */
    public function period(PdvConnection $connection, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $orders = PdvOrder::query()
            ->whereBelongsTo($connection, 'connection')
            ->whereBetween('external_completed_at', [
                $from->setTimezone($timezone)->startOfDay()->utc(),
                $to->setTimezone($timezone)->endOfDay()->utc(),
            ])
            ->with(['connection', 'location', 'items', 'payments', 'officialSaleOrder.payments'])
            ->orderBy('external_completed_at')
            ->get();

        $rows = $orders->map(function (PdvOrder $order): array {
            $reconciliation = $this->orders->reconcile($order);
            $classification = $this->classification($order, $reconciliation);
            $official = $order->officialSaleOrder;

            return [
                'order' => $order,
                'official' => $official,
                'classification' => $classification,
                'blockers' => $reconciliation['blockers'],
                'external_total' => $order->total,
                'official_total' => $official?->status === ProductSaleOrder::STATUS_COMPLETED ? $official->total_amount_snapshot : '0.00',
                'comparable' => in_array($classification, ['imported', 'ready', 'blocked'], true),
            ];
        });

        $externalTotal = $this->sum($rows->pluck('external_total')->all());
        $comparableTotal = $this->sum($rows->where('comparable', true)->pluck('external_total')->all());
        $officialTotal = $this->sum($rows->pluck('official_total')->all());
        $externalPayments = $orders->flatMap(fn (PdvOrder $order) => $order->payments->where('present_in_latest', true))
            ->groupBy(fn ($payment): string => $payment->external_form_description ?: ($payment->external_type ?: 'Não informado'))
            ->map(fn ($payments, string $label): array => ['label' => $label, 'count' => $payments->count(), 'total' => $this->money($this->sum($payments->pluck('amount')->all()))])
            ->values();
        $officialPayments = $orders->pluck('officialSaleOrder')->filter()->flatMap->payments
            ->groupBy('payment_method')
            ->map(fn ($payments, string $label): array => ['label' => $label, 'count' => $payments->count(), 'total' => $this->money($this->sum($payments->pluck('amount')->all()))])
            ->values();

        return [
            'summary' => [
                'external_orders' => $rows->count(),
                'imported' => $rows->where('classification', 'imported')->count(),
                'pre_operational' => $rows->where('classification', 'pre_operational')->count(),
                'blocked' => $rows->where('classification', 'blocked')->count(),
                'ready' => $rows->where('classification', 'ready')->count(),
                'cancelled' => $rows->where('classification', 'cancelled')->count(),
                'reversed' => $rows->where('classification', 'reversed')->count(),
                'external_total' => $this->money($externalTotal),
                'comparable_external_total' => $this->money($comparableTotal),
                'official_total' => $this->money($officialTotal),
                'difference' => $this->money($comparableTotal->minus($officialTotal)),
                'inconsistencies' => $rows->sum(fn (array $row): int => count($row['blockers'])),
            ],
            'external_payments' => $externalPayments,
            'official_payments' => $officialPayments,
            'rows' => $rows,
        ];
    }

    /** @param array<string, mixed> $reconciliation */
    private function classification(PdvOrder $order, array $reconciliation): string
    {
        if ($order->processing_state === PdvOrder::STATE_REVERSED || $order->officialSaleOrder?->status === ProductSaleOrder::STATUS_REVERSED) {
            return 'reversed';
        }
        if ($this->cancelled($order->external_status)) {
            return 'cancelled';
        }
        if (in_array($reconciliation['operational_cutoff']['classification'], ['pre_operational', 'operational_start_pending'], true)) {
            return 'pre_operational';
        }
        if ($order->processing_state === PdvOrder::STATE_IMPORTED && $order->officialSaleOrder !== null) {
            return 'imported';
        }

        return $reconciliation['ready_for_import'] ? 'ready' : 'blocked';
    }

    private function cancelled(string $status): bool
    {
        return in_array(mb_strtolower($status), ['cancelado', 'cancelled', 'voided', 'estornado', 'refunded', 'reversed'], true);
    }

    /** @param array<int, string|null> $values */
    private function sum(array $values): BigDecimal
    {
        return array_reduce($values, fn (BigDecimal $sum, mixed $value): BigDecimal => $sum->plus($value ?? '0'), BigDecimal::zero());
    }

    private function money(BigDecimal $value): string
    {
        return (string) $value->toScale(2, RoundingMode::HalfUp);
    }
}
