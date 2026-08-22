<?php

namespace App\Services;

use App\Models\PdvOrder;
use App\Models\ProductSaleOrder;
use App\Models\ProductSalePayment;
use App\Models\ProductSalePaymentAllocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Pdv\IntegrationNotConfiguredException;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class PdvOrderReversalService
{
    public function __construct(
        private ProductSaleService $sales,
        private PdvConnectionAccessService $access,
        private PdvIntegrationEventService $events,
    ) {}

    public function reverse(PdvOrder $order, User $user, string $reason): ProductSaleOrder
    {
        if (! config('pdv.import_enabled', false)) {
            throw new IntegrationNotConfiguredException('A operação oficial de PDV está desabilitada.');
        }
        $connection = $order->connection()->firstOrFail();
        $this->access->authorizeConnection($user, $connection);
        $this->access->assertOperationalScope($connection);

        return DB::transaction(function () use ($order, $user, $reason, $connection): ProductSaleOrder {
            $locked = PdvOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $official = ProductSaleOrder::query()->where('pdv_order_id', $locked->id)->with(['sales', 'payments.allocations'])->lockForUpdate()->firstOrFail();
            if ($official->status === ProductSaleOrder::STATUS_REVERSED) {
                return $official;
            }
            if (! in_array(mb_strtolower($locked->external_status), ['cancelado', 'cancelled', 'voided', 'estornado', 'refunded', 'reversed'], true)) {
                throw new DomainException('A reversão exige um cancelamento externo reconhecido.');
            }

            foreach ($official->sales as $sale) {
                $this->sales->reversePdvItem($sale, $user, $reason);
            }
            foreach ($official->payments->whereNull('reversal_of_id') as $payment) {
                $reversal = ProductSalePayment::query()->firstOrCreate(
                    ['reversal_of_id' => $payment->id],
                    [
                        'product_sale_order_id' => $official->id,
                        'external_reference' => $payment->external_reference,
                        'payment_method' => $payment->payment_method,
                        'status' => ProductSalePayment::STATUS_REVERSAL,
                        'external_amount_snapshot' => $this->negative($payment->external_amount_snapshot),
                        'amount' => $this->negative($payment->amount),
                        'fee_amount' => $this->negative($payment->fee_amount),
                        'net_amount' => $this->negative($payment->net_amount),
                        'acquirer_id' => $payment->acquirer_id,
                        'card_brand_id' => $payment->card_brand_id,
                        'installment_number' => $payment->installment_number,
                        'installments' => $payment->installments,
                        'fee_percentage_snapshot' => $payment->fee_percentage_snapshot,
                        'fixed_fee_snapshot' => $payment->fixed_fee_snapshot,
                        'payment_fee_id' => $payment->payment_fee_id,
                        'idempotency_key' => "{$payment->idempotency_key}:reversal",
                    ],
                );
                foreach ($payment->allocations as $allocation) {
                    ProductSalePaymentAllocation::query()->firstOrCreate(
                        ['product_sale_payment_id' => $reversal->id, 'product_sale_id' => $allocation->product_sale_id],
                        [
                            'gross_allocated' => $this->negative($allocation->gross_allocated),
                            'revenue_allocated' => $this->negative($allocation->revenue_allocated),
                            'fee_allocated' => $this->negative($allocation->fee_allocated),
                            'net_allocated' => $this->negative($allocation->net_allocated),
                        ],
                    );
                }
            }

            $reversalPaymentIds = ProductSalePayment::query()
                ->whereIn('reversal_of_id', $official->payments->whereNull('reversal_of_id')->pluck('id'))
                ->pluck('id')->all();
            $reversalMovementIds = StockMovement::query()
                ->whereIn('idempotency_key', $official->sales->map(fn ($sale): string => "sale:{$sale->id}:cancelled"))
                ->pluck('id')->all();

            $official->update(['status' => ProductSaleOrder::STATUS_REVERSED, 'reversed_at' => now(), 'reversed_by' => $user->id, 'reversal_reason' => $reason]);
            $locked->update(['processing_state' => PdvOrder::STATE_REVERSED, 'reversed_at' => now()]);
            $this->events->record('order_reversed', $connection, user: $user, status: 'reversed', metadata: [
                'pdv_order_id' => $locked->id,
                'external_order_id' => $locked->external_order_id,
                'location_id' => $official->location_id,
                'product_sale_order_id' => $official->id,
                'product_sale_ids' => $official->sales->pluck('id')->all(),
                'reversal_payment_ids' => $reversalPaymentIds,
                'reversal_stock_movement_ids' => $reversalMovementIds,
                'reason' => $reason,
            ]);

            return $official->refresh()->load(['sales', 'payments.allocations']);
        });
    }

    private function negative(string $value): string
    {
        return (string) BigDecimal::of($value)->negated();
    }
}
