<?php

namespace App\Services;

use App\Models\Location;
use App\Models\PdvOrder;
use App\Models\ProductSale;
use App\Models\ProductSaleOrder;
use App\Models\ProductSalePayment;
use App\Models\ProductSalePaymentAllocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Pdv\IntegrationNotConfiguredException;
use App\Pdv\PdvOrderImportBlockedException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PdvOrderImportService
{
    public function __construct(
        private PdvOrderImportPlanService $plans,
        private ProductSaleService $sales,
        private PdvConnectionAccessService $access,
        private PdvIntegrationEventService $events,
    ) {}

    /** @return array{status:string,order:ProductSaleOrder} */
    public function execute(PdvOrder $order, User $user): array
    {
        if (! config('pdv.import_enabled', false)) {
            throw new IntegrationNotConfiguredException('A importação operacional de PDV está desabilitada.');
        }

        $connection = $order->connection()->firstOrFail();
        $location = $this->access->assertOperationalScope($connection);
        $this->access->authorizeConnection($user, $connection);
        if ($order->location_id !== $location->id) {
            throw new DomainException('O pedido não pertence à unidade operacional da conexão.');
        }

        try {
            return DB::transaction(function () use ($order, $user, $connection, $location): array {
                Location::query()->whereKey($location->id)->lockForUpdate()->firstOrFail();
                $locked = PdvOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->pdv_connection_id !== $connection->id || $locked->location_id !== $location->id) {
                    throw new DomainException('O escopo do pedido mudou durante a importação.');
                }

                $existing = ProductSaleOrder::query()->where('pdv_order_id', $locked->id)->first();
                if ($existing !== null) {
                    return ['status' => $existing->status === ProductSaleOrder::STATUS_REVERSED ? 'already_reversed' : 'already_imported', 'order' => $existing->load(['sales', 'payments.allocations'])];
                }

                $plan = $this->plans->plan($locked);
                if (! $plan['ready']) {
                    throw new PdvOrderImportBlockedException($plan['blockers']);
                }

                $operationDate = $locked->external_completed_at?->setTimezone(config('app.timezone'))->toDateString();
                if ($operationDate === null) {
                    throw new DomainException('A data da operação oficial não está disponível.');
                }

                $official = ProductSaleOrder::query()->create([
                    'location_id' => $location->id,
                    'pdv_connection_id' => $connection->id,
                    'pdv_order_id' => $locked->id,
                    'operation_date' => $operationDate,
                    'entry_source' => 'pdv',
                    'external_reference' => $locked->external_order_id,
                    'status' => ProductSaleOrder::STATUS_COMPLETED,
                    'subtotal_snapshot' => $locked->subtotal,
                    'discount_total_snapshot' => $locked->discount_total,
                    'service_total_snapshot' => $locked->service_total ?? '0',
                    'delivery_total_snapshot' => $locked->delivery_total ?? '0',
                    'total_amount_snapshot' => $locked->total,
                    'paid_total_snapshot' => $locked->paid_total,
                    'change_total_snapshot' => $locked->change_total,
                    'source_hash_snapshot' => $locked->latest_source_hash,
                    'created_by' => $user->id,
                    'imported_at' => now(),
                    'idempotency_key' => $plan['idempotency_key'],
                ]);

                $salesByItem = [];
                foreach ($plan['items'] as $item) {
                    $sale = $this->sales->recordPdvItem([
                        'product_sale_order_id' => $official->id,
                        'product_id' => $item['product']->id,
                        'location_id' => $location->id,
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'subtotal_amount' => $item['subtotal_amount'],
                        'discount_amount' => $item['discount_amount'],
                        'total_amount' => $item['total_amount'],
                        'payment_method_snapshot' => $item['payment_method_snapshot'],
                        'fee_amount_snapshot' => $item['fee_amount_snapshot'],
                        'net_amount_snapshot' => $item['net_amount_snapshot'],
                        'operation_date' => $operationDate,
                        'pdv_connection_id' => $connection->id,
                        'pdv_order_item_id' => $item['item']->id,
                        'external_sale_id' => $locked->external_order_id,
                        'external_item_id' => $item['item']->external_item_id,
                        'external_status' => $locked->external_status,
                        'external_updated_at' => $locked->external_updated_at,
                        'idempotency_key' => $item['idempotency_key'],
                        'notes' => 'Item importado de pedido PDV confirmado.',
                    ], $user);
                    $salesByItem[$item['item']->id] = $sale;
                }

                foreach ($plan['payments'] as $paymentPlan) {
                    $mapping = $paymentPlan['mapping'];
                    $payment = ProductSalePayment::query()->create([
                        'product_sale_order_id' => $official->id,
                        'pdv_order_payment_id' => $paymentPlan['payment']->id,
                        'external_reference' => $paymentPlan['payment']->external_payment_id,
                        'payment_method' => $paymentPlan['payment_method'],
                        'status' => ProductSalePayment::STATUS_COMPLETED,
                        'external_amount_snapshot' => $paymentPlan['external_amount'],
                        'amount' => $paymentPlan['amount'],
                        'fee_amount' => $paymentPlan['fee_amount'],
                        'net_amount' => $paymentPlan['net_amount'],
                        'acquirer_id' => $mapping->acquirer_id,
                        'card_brand_id' => $mapping->card_brand_id,
                        'installment_number' => $paymentPlan['payment']->installment_number,
                        'installments' => $paymentPlan['payment']->installments,
                        'fee_percentage_snapshot' => $paymentPlan['fee_percentage'],
                        'fixed_fee_snapshot' => $paymentPlan['fixed_fee'],
                        'payment_fee_id' => $paymentPlan['payment_fee_id'],
                        'idempotency_key' => $paymentPlan['idempotency_key'],
                    ]);
                    foreach ($paymentPlan['allocations'] as $allocation) {
                        ProductSalePaymentAllocation::query()->create([
                            'product_sale_payment_id' => $payment->id,
                            'product_sale_id' => $salesByItem[$allocation['item_id']]->id,
                            'gross_allocated' => $allocation['gross_allocated'],
                            'revenue_allocated' => $allocation['revenue_allocated'],
                            'fee_allocated' => $allocation['fee_allocated'],
                            'net_allocated' => $allocation['net_allocated'],
                        ]);
                    }
                }

                $this->assertPersistedSums($official, count($plan['items']));
                $official->load(['sales', 'payments.allocations']);
                $movementIds = StockMovement::query()
                    ->where('reference_type', ProductSale::class)
                    ->whereIn('reference_id', $official->sales->pluck('id')->map(fn (int $id): string => (string) $id))
                    ->pluck('id')->all();
                $locked->update(['processing_state' => PdvOrder::STATE_IMPORTED, 'imported_at' => now()]);
                $this->events->record('order_imported', $connection, user: $user, status: 'imported', metadata: [
                    'pdv_order_id' => $locked->id,
                    'external_order_id' => $locked->external_order_id,
                    'location_id' => $location->id,
                    'product_sale_order_id' => $official->id,
                    'product_sale_ids' => $official->sales->pluck('id')->all(),
                    'product_sale_payment_ids' => $official->payments->pluck('id')->all(),
                    'stock_movement_ids' => $movementIds,
                    'items' => count($plan['items']),
                    'payments' => count($plan['payments']),
                ]);

                return ['status' => 'imported', 'order' => $official->load(['sales', 'payments.allocations'])];
            });
        } catch (QueryException $exception) {
            $existing = ProductSaleOrder::query()->where('pdv_order_id', $order->id)->first();
            if ($existing === null) {
                throw $exception;
            }

            return ['status' => $existing->status === ProductSaleOrder::STATUS_REVERSED ? 'already_reversed' : 'already_imported', 'order' => $existing->load(['sales', 'payments.allocations'])];
        }
    }

    private function assertPersistedSums(ProductSaleOrder $order, int $expectedItems): void
    {
        $order->load(['sales', 'payments.allocations']);
        if ($order->sales->count() !== $expectedItems) {
            throw new DomainException('A quantidade de itens oficiais diverge do plano de importação.');
        }
        foreach ($order->payments as $payment) {
            $gross = $payment->allocations->reduce(fn (BigDecimal $sum, $allocation): BigDecimal => $sum->plus($allocation->gross_allocated), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp);
            $fees = $payment->allocations->reduce(fn (BigDecimal $sum, $allocation): BigDecimal => $sum->plus($allocation->fee_allocated), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp);
            $net = $payment->allocations->reduce(fn (BigDecimal $sum, $allocation): BigDecimal => $sum->plus($allocation->net_allocated), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp);
            if (! $gross->isEqualTo($payment->amount) || ! $fees->isEqualTo($payment->fee_amount) || ! $net->isEqualTo($payment->net_amount)) {
                throw new DomainException('As alocações financeiras não fecham com o pagamento oficial.');
            }
        }
    }
}
