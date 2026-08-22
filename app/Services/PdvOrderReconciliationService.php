<?php

namespace App\Services;

use App\Models\PaymentFee;
use App\Models\PdvOrder;
use App\Models\PdvOrderItem;
use App\Models\PdvOrderPayment;
use App\Models\PdvPaymentMethodMapping;
use App\Models\PdvProductMapping;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

class PdvOrderReconciliationService
{
    private const TOLERANCE = '0.01';

    /** @var array<int, Collection<string, PdvProductMapping>> */
    private array $productMappingCache = [];

    /** @var array<int, Collection<string, PdvPaymentMethodMapping>> */
    private array $paymentMappingCache = [];

    /** @var array<string, string> */
    private array $balanceCache = [];

    /** @var array<string, ?PaymentFee> */
    private array $feeCache = [];

    public function __construct(
        private StockBalanceService $balances,
        private PdvPaymentCompatibilityService $paymentCompatibility,
        private PaymentFeeResolver $fees,
        private PdvOperationalCutoffService $cutoffs,
    ) {}

    /** @return array<string, mixed> */
    public function reconcile(PdvOrder $order): array
    {
        $order->loadMissing(['connection', 'location', 'items', 'payments']);
        $blockers = [];
        $warnings = [];
        $items = $order->items->where('present_in_latest', true)->values();
        $payments = $order->payments->where('present_in_latest', true)->values();

        if ($order->connection === null || $order->location === null || $order->connection->location_id !== $order->location_id) {
            $this->block($blockers, 'location_scope_mismatch', 'O pedido não pertence à unidade da conexão.');
        }

        $operationalCutoff = $order->connection === null
            ? [
                'operational_start_at' => null,
                'order_completed_at' => $order->external_completed_at?->toIso8601String(),
                'is_after_operational_start' => null,
                'importable_by_cutoff' => false,
                'classification' => 'invalid_connection_scope',
                'blocker' => null,
            ]
            : $this->cutoffs->assess($order->connection, $order->external_completed_at);
        if ($operationalCutoff['blocker'] !== null) {
            $this->block($blockers, $operationalCutoff['blocker']['code'], $operationalCutoff['blocker']['message']);
        }

        $cancelled = $this->cancelled($order->external_status);
        if ($cancelled) {
            $this->block($blockers, 'order_cancelled', $order->processing_state === PdvOrder::STATE_IMPORTED
                ? 'O pedido foi importado anteriormente e agora está cancelado; exigirá reversão oficial.'
                : 'O pedido externo está cancelado e não deve ser importado.');
        }
        if ($order->processing_state === PdvOrder::STATE_IMPORTED) {
            $this->block($blockers, 'already_imported', 'O pedido já foi importado.');
        }
        if ($order->processing_state === PdvOrder::STATE_REVERSED) {
            $this->block($blockers, 'already_reversed', 'O pedido já foi revertido.');
        }
        if ($order->source_hash !== $order->latest_source_hash) {
            $this->block($blockers, 'source_changed_after_import', 'A fonte mudou depois do snapshot operacional e exige reconciliação explícita.');
        }

        $productStatus = $this->products($order, $items->all(), $cancelled, $blockers);
        $paymentStatus = $this->payments($order, $payments->all(), $cancelled, $blockers);
        $stockStatus = $operationalCutoff['importable_by_cutoff']
            ? $this->stock($order, $productStatus['items'], $cancelled, $blockers)
            : ['ready' => true, 'products' => [], 'skipped_by_operational_cutoff' => true];
        $totalsStatus = $this->totals($order, $items->all(), $payments->all(), $warnings, $blockers);

        return [
            'ready_for_import' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'operational_cutoff' => $operationalCutoff,
            'product_mapping_status' => $productStatus,
            'payment_mapping_status' => $paymentStatus,
            'stock_status' => $stockStatus,
            'totals_status' => $totalsStatus,
        ];
    }

    /** @param array<int, PdvOrderItem> $items @param array<int, array<string, mixed>> $blockers */
    private function products(PdvOrder $order, array $items, bool $cancelledOrder, array &$blockers): array
    {
        $results = [];
        $allMapped = true;
        foreach ($items as $item) {
            if ($item->cancelled || $cancelledOrder) {
                $results[] = ['item' => $item, 'mapping' => null, 'product' => null, 'valid' => true, 'reason' => 'cancelled'];

                continue;
            }
            $mapping = $item->external_product_id === null ? null : $this->productMappings($order->pdv_connection_id)->get($item->external_product_id);
            $product = $mapping?->product;
            $reason = null;
            if ($mapping === null) {
                $reason = 'missing';
                $this->block($blockers, 'product_mapping_missing', "Produto externo sem mapping: {$item->description}.", ['item_id' => $item->id]);
            } elseif ($mapping->status !== 'confirmed' || $mapping->product_id === null) {
                $reason = 'not_confirmed';
                $this->block($blockers, 'product_mapping_not_confirmed', "Mapping de produto não confirmado: {$item->description}.", ['item_id' => $item->id]);
            } elseif (! $product?->active) {
                $reason = 'inactive_product';
                $this->block($blockers, 'mapped_product_inactive', "Produto ERP inativo: {$item->description}.", ['item_id' => $item->id]);
            }
            $valid = $reason === null;
            $allMapped = $allMapped && $valid;
            $results[] = compact('item', 'mapping', 'product', 'valid', 'reason');
        }

        return ['ready' => $allMapped, 'items' => $results];
    }

    /** @param array<int, PdvOrderPayment> $payments @param array<int, array<string, mixed>> $blockers */
    private function payments(PdvOrder $order, array $payments, bool $cancelledOrder, array &$blockers): array
    {
        $results = [];
        $allMapped = true;
        if ($payments === [] && ! $cancelledOrder && BigDecimal::of($order->total)->isPositive()) {
            $allMapped = false;
            $this->block($blockers, 'payment_missing', 'O pedido não possui pagamento externo para reconciliar.');
        }
        foreach ($payments as $payment) {
            if (! $this->paymentRelevant($payment) || $cancelledOrder) {
                $results[] = ['payment' => $payment, 'mapping' => null, 'fee' => null, 'valid' => true, 'reason' => 'not_relevant', 'compatibility' => null];

                continue;
            }
            $mapping = $payment->external_form_id === null ? null : $this->paymentMappings($order->pdv_connection_id)->get($payment->external_form_id);
            $compatibility = $this->paymentCompatibility->forExternal($payment->external_form_description, $payment->external_type);
            $reason = null;
            $fee = null;
            if (! $compatibility['supported']) {
                $reason = 'unsupported_method';
                $this->block($blockers, 'payment_mapping_unsupported', $compatibility['reason'] ?? 'A forma de pagamento não é suportada.', ['payment_id' => $payment->id]);
            } elseif ($mapping === null) {
                $reason = 'missing';
                $this->block($blockers, 'payment_mapping_missing', 'Forma de pagamento externa sem mapping.', ['payment_id' => $payment->id]);
            } elseif ($mapping->status !== 'confirmed' || $mapping->payment_method === null) {
                $reason = 'not_confirmed';
                $this->block($blockers, 'payment_mapping_not_confirmed', 'Mapping financeiro não confirmado.', ['payment_id' => $payment->id]);
            } elseif (! $this->paymentCompatibility->supportsMethod($mapping->payment_method) || $mapping->payment_method !== $compatibility['method']) {
                $reason = 'unsupported_method';
                $this->block($blockers, 'payment_mapping_unsupported', 'O mapping não representa corretamente a forma externa.', ['payment_id' => $payment->id]);
            } elseif (in_array($mapping->payment_method, ['debit', 'credit'], true) && ($mapping->acquirer_id === null || $mapping->card_brand_id === null || ! $mapping->acquirer?->active || ! $mapping->cardBrand?->active)) {
                $reason = 'incomplete_financial_mapping';
                $this->block($blockers, 'payment_mapping_incomplete', 'O mapping de cartão precisa de adquirente e bandeira ativos.', ['payment_id' => $payment->id]);
            } elseif (in_array($mapping->payment_method, ['debit', 'credit'], true)) {
                $fee = $this->resolveFee($order, $payment, $mapping);
                if ($fee === null) {
                    $reason = 'rate_missing';
                    $this->block($blockers, 'payment_rate_missing', 'Não existe taxa financeira vigente para a forma de pagamento mapeada.', ['payment_id' => $payment->id]);
                }
            }
            $valid = $reason === null;
            $allMapped = $allMapped && $valid;
            $results[] = compact('payment', 'mapping', 'fee', 'valid', 'reason', 'compatibility');
        }

        return ['ready' => $allMapped, 'payments' => $results];
    }

    /** @param array<int, array<string, mixed>> $mappedItems @param array<int, array<string, mixed>> $blockers */
    private function stock(PdvOrder $order, array $mappedItems, bool $cancelledOrder, array &$blockers): array
    {
        $requirements = [];
        foreach ($mappedItems as $mapped) {
            if ($cancelledOrder || ! $mapped['valid'] || $mapped['item']->cancelled || $mapped['product'] === null) {
                continue;
            }
            $productId = $mapped['product']->id;
            $requirements[$productId] ??= ['product' => $mapped['product'], 'required' => BigDecimal::zero()];
            $requirements[$productId]['required'] = $requirements[$productId]['required']->plus($mapped['item']->quantity);
        }

        $results = [];
        $sufficient = true;
        foreach ($requirements as $productId => $requirement) {
            $required = $requirement['required']->toScale(6, RoundingMode::HalfUp);
            $balanceKey = $order->location_id.':'.$productId;
            $available = BigDecimal::of($this->balanceCache[$balanceKey] ??= $this->balances->balance($productId, $order->location_id))->toScale(6, RoundingMode::HalfUp);
            $valid = ! $available->isLessThan($required);
            if (! $valid) {
                $sufficient = false;
                $this->block($blockers, 'stock_insufficient', "Estoque insuficiente para {$requirement['product']->name}.", ['product_id' => $productId]);
            }
            $results[] = ['product' => $requirement['product'], 'required' => (string) $required, 'available' => (string) $available, 'valid' => $valid];
        }

        return ['ready' => $sufficient, 'products' => $results];
    }

    /** @param array<int, PdvOrderItem> $items @param array<int, PdvOrderPayment> $payments @param array<int, array<string, mixed>> $warnings @param array<int, array<string, mixed>> $blockers */
    private function totals(PdvOrder $order, array $items, array $payments, array &$warnings, array &$blockers): array
    {
        $activeItems = collect($items)->reject(fn (PdvOrderItem $item): bool => $item->cancelled);
        $relevantPayments = collect($payments)->filter(fn (PdvOrderPayment $payment): bool => $this->paymentRelevant($payment));
        $itemTotal = $activeItems->reduce(fn (BigDecimal $total, PdvOrderItem $item): BigDecimal => $total->plus($item->total), BigDecimal::zero());
        $paymentTotal = $relevantPayments->reduce(fn (BigDecimal $total, PdvOrderPayment $payment): BigDecimal => $total->plus($payment->amount), BigDecimal::zero());
        $service = BigDecimal::of($order->service_total ?? '0');
        $delivery = BigDecimal::of($order->delivery_total ?? '0');
        $expectedFromItems = $itemTotal->plus($service)->plus($delivery);
        $expectedWithHeaderDiscount = $itemTotal->minus($order->discount_total)->plus($service)->plus($delivery);
        $itemsMatch = $this->close($expectedFromItems, BigDecimal::of($order->total))
            || $this->close($expectedWithHeaderDiscount, BigDecimal::of($order->total));
        if (! $itemsMatch) {
            $this->block($blockers, 'item_total_mismatch', 'A soma dos itens e adicionais diverge do total do pedido.');
        }
        if (! BigDecimal::of($order->discount_total)->isZero()
            && ! $this->close($expectedFromItems, BigDecimal::of($order->total))
            && $this->close($expectedWithHeaderDiscount, BigDecimal::of($order->total))) {
            $warnings[] = ['code' => 'header_discount_requires_allocation', 'message' => 'O desconto do cabeçalho será alocado deterministicamente entre os itens no plano oficial.'];
        }

        $paymentsMatch = $order->paid_total === null || $this->close($paymentTotal, BigDecimal::of($order->paid_total));
        if (! $paymentsMatch) {
            $this->block($blockers, 'payment_total_mismatch', 'A soma dos pagamentos diverge do valor pago informado pelo PDV.');
        }

        $changeMatch = $order->paid_total === null || $order->change_total === null
            || $this->close(BigDecimal::of($order->paid_total)->minus($order->change_total), BigDecimal::of($order->total));
        if (! $changeMatch) {
            $this->block($blockers, 'change_total_mismatch', 'Pago menos troco diverge do total do pedido.');
        }

        $formulaTotal = BigDecimal::of($order->subtotal)
            ->minus($order->discount_total)
            ->plus($service)
            ->plus($delivery);
        $formulaMatch = $this->close($formulaTotal, BigDecimal::of($order->total));
        if (! $formulaMatch) {
            $warnings[] = ['code' => 'external_formula_unconfirmed', 'message' => 'Subtotal, desconto e adicionais foram preservados, mas a fórmula externa não fecha; nenhuma semântica foi inventada.'];
        }

        return [
            'ready' => $itemsMatch && $paymentsMatch && $changeMatch,
            'item_total' => $this->money($itemTotal),
            'payment_total' => $this->money($paymentTotal),
            'order_subtotal' => $order->subtotal,
            'discount_total' => $order->discount_total,
            'service_total' => $order->service_total,
            'delivery_total' => $order->delivery_total,
            'order_total' => $order->total,
            'paid_total' => $order->paid_total,
            'change_total' => $order->change_total,
            'items_match' => $itemsMatch,
            'payments_match' => $paymentsMatch,
            'change_match' => $changeMatch,
            'external_formula_match' => $formulaMatch,
        ];
    }

    /** @return Collection<string, PdvProductMapping> */
    private function productMappings(int $connectionId): Collection
    {
        return $this->productMappingCache[$connectionId] ??= PdvProductMapping::query()
            ->where('pdv_connection_id', $connectionId)
            ->with('product')
            ->get()
            ->keyBy('external_product_id');
    }

    /** @return Collection<string, PdvPaymentMethodMapping> */
    private function paymentMappings(int $connectionId): Collection
    {
        return $this->paymentMappingCache[$connectionId] ??= PdvPaymentMethodMapping::query()
            ->where('pdv_connection_id', $connectionId)
            ->with(['acquirer', 'cardBrand'])
            ->get()
            ->keyBy('external_method_code');
    }

    private function resolveFee(PdvOrder $order, PdvOrderPayment $payment, PdvPaymentMethodMapping $mapping): ?PaymentFee
    {
        $operationDate = $order->external_completed_at?->setTimezone(config('app.timezone'))->toDateString();
        if ($operationDate === null || $mapping->acquirer_id === null || $mapping->card_brand_id === null || $mapping->payment_method === null) {
            return null;
        }
        $key = implode(':', [$mapping->acquirer_id, $mapping->card_brand_id, $mapping->payment_method, $payment->installments ?? '', $operationDate]);

        return $this->feeCache[$key] ??= $this->fees->resolve($mapping->acquirer_id, $mapping->card_brand_id, $mapping->payment_method, $payment->installments, $operationDate);
    }

    private function paymentRelevant(PdvOrderPayment $payment): bool
    {
        return ! in_array(mb_strtolower((string) $payment->external_status), ['cancelado', 'cancelled', 'voided', 'estornado', 'refunded'], true);
    }

    private function cancelled(string $status): bool
    {
        return in_array(mb_strtolower($status), ['cancelado', 'cancelled', 'voided', 'estornado', 'refunded', 'reversed'], true);
    }

    private function close(BigDecimal $left, BigDecimal $right): bool
    {
        return $left->minus($right)->abs()->isLessThanOrEqualTo(self::TOLERANCE);
    }

    private function money(BigDecimal $value): string
    {
        return (string) $value->toScale(2, RoundingMode::HalfUp);
    }

    /** @param array<int, array<string, mixed>> $blockers @param array<string, mixed> $context */
    private function block(array &$blockers, string $code, string $message, array $context = []): void
    {
        $blockers[] = array_merge(compact('code', 'message'), $context);
    }
}
