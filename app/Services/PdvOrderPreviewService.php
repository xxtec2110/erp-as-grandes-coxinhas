<?php

namespace App\Services;

use App\Models\PdvConnection;
use App\Models\PdvOrder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

class PdvOrderPreviewService
{
    public function __construct(private PdvOrderReconciliationService $reconciliation) {}

    /** @return array<string, mixed> */
    public function order(PdvOrder $order): array
    {
        $order->loadMissing(['connection', 'location', 'items', 'payments']);
        $reconciliation = $this->reconciliation->reconcile($order);

        return ['order' => $order, 'reconciliation' => $reconciliation];
    }

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
            ->with(['location', 'items', 'payments'])
            ->orderBy('external_completed_at')
            ->get();
        $previews = $orders->map(fn (PdvOrder $order): array => $this->order($order))->all();
        $blockerCodes = collect($previews)->flatMap(fn (array $preview) => collect($preview['reconciliation']['blockers'])->pluck('code'));
        $total = $orders->reduce(fn (BigDecimal $sum, PdvOrder $order): BigDecimal => $sum->plus($order->total), BigDecimal::zero());

        return [
            'summary' => [
                'staged' => count($previews),
                'ready' => collect($previews)->where('reconciliation.ready_for_import', true)->count(),
                'blocked' => collect($previews)->where('reconciliation.ready_for_import', false)->count(),
                'product_mapping_pending' => $this->ordersWith($previews, ['product_mapping_missing', 'product_mapping_not_confirmed', 'mapped_product_inactive']),
                'payment_mapping_pending' => $this->ordersWith($previews, ['payment_missing', 'payment_mapping_missing', 'payment_mapping_not_confirmed', 'payment_mapping_incomplete']),
                'payment_unsupported' => $this->ordersWith($previews, ['payment_mapping_unsupported', 'payment_method_not_operational']),
                'payment_rate_missing' => $this->ordersWith($previews, ['payment_rate_missing']),
                'stock_insufficient' => $this->ordersWith($previews, ['stock_insufficient']),
                'value_mismatch' => $this->ordersWith($previews, ['item_total_mismatch', 'payment_total_mismatch', 'change_total_mismatch']),
                'split_payments' => $orders->filter(fn (PdvOrder $order): bool => $order->payments->where('present_in_latest', true)->count() > 1)->count(),
                'total' => (string) $total->toScale(2, RoundingMode::HalfUp),
                'blocker_codes' => $blockerCodes->countBy()->all(),
            ],
            'orders' => $previews,
        ];
    }

    /** @param array<int, array<string, mixed>> $previews @param array<int, string> $codes */
    private function ordersWith(array $previews, array $codes): int
    {
        return collect($previews)->filter(function (array $preview) use ($codes): bool {
            return collect($preview['reconciliation']['blockers'])->contains(fn (array $blocker): bool => in_array($blocker['code'], $codes, true));
        })->count();
    }
}
