<?php

namespace App\Services;

use App\Models\PdvOrder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class PdvOrderImportPlanService
{
    public function __construct(
        private PdvOrderReconciliationService $reconciliation,
        private PaymentFeeCalculator $feeCalculator,
        private MoneyAllocationService $allocator,
    ) {}

    /** @return array<string, mixed> */
    public function plan(PdvOrder $order): array
    {
        $order->loadMissing(['connection', 'location', 'items', 'payments']);
        $reconciliation = $this->reconciliation->reconcile($order);
        $operationalCutoff = $reconciliation['operational_cutoff'];
        $blockers = $reconciliation['blockers'];
        $warnings = $reconciliation['warnings'];

        if ($order->external_completed_at === null) {
            $this->block($blockers, 'operation_date_missing', 'O pedido não possui data de conclusão para a operação oficial.');
        }

        $itemPlan = $operationalCutoff['importable_by_cutoff']
            ? $this->items($order, $reconciliation, $blockers, $warnings)
            : ['items' => [], 'product_revenue' => BigDecimal::zero()->toScale(2), 'discount_allocated' => BigDecimal::zero()->toScale(2)];
        $paymentPlan = $operationalCutoff['importable_by_cutoff']
            ? $this->payments($order, $reconciliation, $itemPlan['items'], $blockers)
            : ['payments' => [], 'amount' => BigDecimal::zero()->toScale(2), 'fee' => BigDecimal::zero()->toScale(2), 'net' => BigDecimal::zero()->toScale(2)];
        $items = $operationalCutoff['importable_by_cutoff']
            ? $this->attachItemFinancials($itemPlan['items'], $paymentPlan['payments'])
            : [];
        $ready = $blockers === [];
        $enabled = (bool) config('pdv.import_enabled', false);
        $stockAfter = collect($reconciliation['stock_status']['products'] ?? [])->map(fn (array $row): array => [
            'product' => $row['product'],
            'required' => $row['required'],
            'available' => $row['available'],
            'balance_after' => (string) BigDecimal::of($row['available'])->minus($row['required'])->toScale(6, RoundingMode::HalfUp),
            'valid' => $row['valid'],
        ])->values()->all();

        return [
            'order' => $order,
            'operational_start_at' => $operationalCutoff['operational_start_at'],
            'order_completed_at' => $operationalCutoff['order_completed_at'],
            'is_after_operational_start' => $operationalCutoff['is_after_operational_start'],
            'importable_by_cutoff' => $operationalCutoff['importable_by_cutoff'],
            'operational_classification' => $operationalCutoff['classification'],
            'reconciliation' => $reconciliation,
            'items' => $items,
            'payments' => $paymentPlan['payments'],
            'movements' => collect($items)->map(fn (array $item): array => [
                'product_id' => $item['product']->id,
                'product_name' => $item['product']->name,
                'quantity_delta' => (string) BigDecimal::of($item['quantity'])->negated(),
                'location_id' => $order->location_id,
            ])->all(),
            'stock_after' => $stockAfter,
            'planned_counts' => [
                'order_headers' => $ready ? 1 : 0,
                'product_sales' => count($items),
                'payments' => count($paymentPlan['payments']),
                'stock_movements' => count($items),
            ],
            'totals' => [
                'product_revenue' => $this->money($itemPlan['product_revenue']),
                'discount_allocated' => $this->money($itemPlan['discount_allocated']),
                'payment_amount' => $this->money($paymentPlan['amount']),
                'payment_fee' => $this->money($paymentPlan['fee']),
                'payment_net' => $this->money($paymentPlan['net']),
                'external_paid' => $order->paid_total,
                'change' => $order->change_total,
                'order_total' => $order->total,
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'ready' => $ready,
            'import_enabled' => $enabled,
            'can_execute' => $ready && $enabled,
            'idempotency_key' => "pdv-order:{$order->pdv_connection_id}:{$order->external_order_id}",
        ];
    }

    /** @param array<int, array<string, mixed>> $blockers @param array<int, array<string, mixed>> $warnings
     * @return array{items:array<int,array<string,mixed>>,product_revenue:BigDecimal,discount_allocated:BigDecimal}
     */
    private function items(PdvOrder $order, array $reconciliation, array &$blockers, array &$warnings): array
    {
        $activeRows = collect($reconciliation['product_mapping_status']['items'])
            ->filter(fn (array $row): bool => ! $row['item']->cancelled);
        $mapped = $activeRows
            ->filter(fn (array $row): bool => $row['valid'] && ! $row['item']->cancelled && $row['product'] !== null)
            ->sortBy(fn (array $row): string => (string) $row['item']->external_item_id)
            ->values();
        if ($mapped->count() !== $activeRows->count()) {
            return ['items' => [], 'product_revenue' => BigDecimal::zero()->toScale(2), 'discount_allocated' => BigDecimal::zero()->toScale(2)];
        }
        $sourceTotals = $mapped->map(fn (array $row): string => $row['item']->total)->all();
        $stableKeys = $mapped->map(fn (array $row): string => (string) $row['item']->external_item_id)->all();
        $sourceTotal = $this->sum($sourceTotals);
        $discount = BigDecimal::of($order->discount_total ?? '0')->toScale(2, RoundingMode::HalfUp);
        $extras = BigDecimal::of($order->service_total ?? '0')->plus($order->delivery_total ?? '0')->toScale(2, RoundingMode::HalfUp);
        $orderTotal = BigDecimal::of($order->total)->toScale(2, RoundingMode::HalfUp);
        $embeddedCandidate = $sourceTotal->plus($extras);
        $headerCandidate = $sourceTotal->minus($discount)->plus($extras);
        $discounts = array_fill(0, $mapped->count(), '0.00');

        if (! $discount->isZero() && $this->equalMoney($headerCandidate, $orderTotal) && ! $this->equalMoney($embeddedCandidate, $orderTotal)) {
            $discounts = $this->allocator->allocate((string) $discount, $sourceTotals, $stableKeys);
        } elseif (! $this->equalMoney($embeddedCandidate, $orderTotal)) {
            $this->block($blockers, 'item_total_mismatch', 'Os valores dos itens não permitem uma alocação determinística do total do pedido.');
        } elseif (! $discount->isZero()) {
            $warnings[] = ['code' => 'discount_embedded_in_items', 'message' => 'O desconto externo já está refletido nos totais dos itens e não foi aplicado novamente.'];
        }

        $items = [];
        foreach ($mapped as $index => $row) {
            $item = $row['item'];
            if ($item->unit_price === null) {
                $this->block($blockers, 'item_unit_price_missing', "O item {$item->description} não possui preço unitário.", ['item_id' => $item->id]);

                continue;
            }
            $subtotal = BigDecimal::of($item->total)->toScale(2, RoundingMode::HalfUp);
            $itemDiscount = BigDecimal::of($discounts[$index])->toScale(2, RoundingMode::HalfUp);
            $total = $subtotal->minus($itemDiscount)->toScale(2, RoundingMode::HalfUp);
            if ($total->isNegative()) {
                $this->block($blockers, 'item_discount_invalid', "O desconto do item {$item->description} excede seu valor.", ['item_id' => $item->id]);

                continue;
            }
            $items[] = [
                'item' => $item,
                'product' => $row['product'],
                'mapping' => $row['mapping'],
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal_amount' => (string) $subtotal,
                'discount_amount' => (string) $itemDiscount,
                'total_amount' => (string) $total,
                'idempotency_key' => "pdv:{$order->pdv_connection_id}:{$order->external_order_id}:{$item->external_item_id}",
            ];
        }

        return [
            'items' => $items,
            'product_revenue' => $this->sum(array_column($items, 'total_amount')),
            'discount_allocated' => $this->sum(array_column($items, 'discount_amount')),
        ];
    }

    /** @param array<int,array<string,mixed>> $items @param array<int,array<string,mixed>> $blockers
     * @return array{payments:array<int,array<string,mixed>>,amount:BigDecimal,fee:BigDecimal,net:BigDecimal}
     */
    private function payments(PdvOrder $order, array $reconciliation, array $items, array &$blockers): array
    {
        $relevantRows = collect($reconciliation['payment_mapping_status']['payments'])
            ->reject(fn (array $row): bool => $row['reason'] === 'not_relevant');
        $rows = $relevantRows
            ->filter(fn (array $row): bool => $row['valid'] && $row['mapping'] !== null)
            ->sortBy(fn (array $row): string => (string) $row['payment']->external_payment_id)
            ->values();
        if ($rows->count() !== $relevantRows->count() || $items === []) {
            return ['payments' => [], 'amount' => BigDecimal::zero()->toScale(2), 'fee' => BigDecimal::zero()->toScale(2), 'net' => BigDecimal::zero()->toScale(2)];
        }
        $remainingChange = BigDecimal::of($order->change_total ?? '0')->toScale(2, RoundingMode::HalfUp);
        $prepared = [];
        foreach ($rows as $row) {
            $payment = $row['payment'];
            $mapping = $row['mapping'];
            $externalAmount = BigDecimal::of($payment->amount)->toScale(2, RoundingMode::HalfUp);
            $amount = $externalAmount;
            if ($mapping->payment_method === 'cash' && $remainingChange->isPositive()) {
                $deduction = $amount->isLessThan($remainingChange) ? $amount : $remainingChange;
                $amount = $amount->minus($deduction);
                $remainingChange = $remainingChange->minus($deduction);
            }
            $percentage = $row['fee']?->fee_percentage ?? '0';
            $fixed = $row['fee']?->fixed_fee ?? '0';
            $financial = $this->feeCalculator->calculate((string) $amount, $percentage, $fixed);
            $prepared[] = [
                'payment' => $payment,
                'mapping' => $mapping,
                'payment_method' => $mapping->payment_method,
                'external_amount' => (string) $externalAmount,
                'amount' => (string) $amount->toScale(2, RoundingMode::HalfUp),
                'fee_amount' => $financial['fee_amount'],
                'net_amount' => $financial['net_amount'],
                'fee_percentage' => (string) BigDecimal::of($percentage)->toScale(6, RoundingMode::HalfUp),
                'fixed_fee' => (string) BigDecimal::of($fixed)->toScale(4, RoundingMode::HalfUp),
                'payment_fee_id' => $row['fee']?->id,
                'idempotency_key' => "pdv-order-payment:{$payment->id}",
            ];
        }

        if ($remainingChange->isPositive()) {
            $this->block($blockers, 'change_without_cash', 'O troco informado não pode ser associado a um pagamento em dinheiro.');
        }
        $paymentAmount = $this->sum(array_column($prepared, 'amount'));
        if (! $this->equalMoney($paymentAmount, BigDecimal::of($order->total))) {
            $this->block($blockers, 'official_payment_total_mismatch', 'Pagamentos líquidos de troco não fecham com o total oficial do pedido.');
        }
        if ($prepared !== [] && $items !== []) {
            $itemWeights = array_column($items, 'total_amount');
            $itemKeys = array_map(fn (array $item): string => (string) $item['item']->external_item_id, $items);
            $paymentWeights = array_column($prepared, 'amount');
            $paymentKeys = array_map(fn (array $payment): string => (string) $payment['payment']->external_payment_id, $prepared);
            $revenueShares = $this->allocator->allocate((string) $this->sum($itemWeights), $paymentWeights, $paymentKeys);
            foreach ($prepared as $paymentIndex => &$payment) {
                $gross = $this->allocator->allocate($payment['amount'], $itemWeights, $itemKeys);
                $revenue = $this->allocator->allocate($revenueShares[$paymentIndex], $itemWeights, $itemKeys);
                $fees = $this->allocator->allocate($payment['fee_amount'], $gross, $itemKeys);
                $payment['allocations'] = [];
                foreach ($items as $itemIndex => $item) {
                    $payment['allocations'][] = [
                        'item_id' => $item['item']->id,
                        'gross_allocated' => $gross[$itemIndex],
                        'revenue_allocated' => $revenue[$itemIndex],
                        'fee_allocated' => $fees[$itemIndex],
                        'net_allocated' => $this->money(BigDecimal::of($gross[$itemIndex])->minus($fees[$itemIndex])),
                    ];
                }
            }
            unset($payment);
        }

        return [
            'payments' => $prepared,
            'amount' => $paymentAmount,
            'fee' => $this->sum(array_column($prepared, 'fee_amount')),
            'net' => $this->sum(array_column($prepared, 'net_amount')),
        ];
    }

    /** @param array<int,array<string,mixed>> $items @param array<int,array<string,mixed>> $payments
     * @return array<int,array<string,mixed>>
     */
    private function attachItemFinancials(array $items, array $payments): array
    {
        $methods = collect($payments)->pluck('payment_method')->unique()->values();
        $method = $methods->count() === 1 ? (string) $methods->first() : 'mixed';
        foreach ($items as &$item) {
            $fee = BigDecimal::zero();
            foreach ($payments as $payment) {
                $allocation = collect($payment['allocations'] ?? [])->firstWhere('item_id', $item['item']->id);
                if ($allocation !== null) {
                    $fee = $fee->plus($allocation['fee_allocated']);
                }
            }
            $item['payment_method_snapshot'] = $method;
            $item['fee_amount_snapshot'] = $this->money($fee);
            $item['net_amount_snapshot'] = $this->money(BigDecimal::of($item['total_amount'])->minus($fee));
        }
        unset($item);

        return $items;
    }

    /** @param array<int,string> $values */
    private function sum(array $values): BigDecimal
    {
        return array_reduce($values, fn (BigDecimal $sum, string $value): BigDecimal => $sum->plus($value), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp);
    }

    private function equalMoney(BigDecimal $left, BigDecimal $right): bool
    {
        return $left->toScale(2, RoundingMode::HalfUp)->isEqualTo($right->toScale(2, RoundingMode::HalfUp));
    }

    private function money(BigDecimal $value): string
    {
        return (string) $value->toScale(2, RoundingMode::HalfUp);
    }

    /** @param array<int,array<string,mixed>> $blockers @param array<string,mixed> $context */
    private function block(array &$blockers, string $code, string $message, array $context = []): void
    {
        if (collect($blockers)->contains(fn (array $blocker): bool => $blocker['code'] === $code && ($context === [] || collect($context)->every(fn (mixed $value, string $key): bool => ($blocker[$key] ?? null) === $value)))) {
            return;
        }

        $blockers[] = array_merge(compact('code', 'message'), $context);
    }
}
