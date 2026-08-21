<?php

namespace App\Services;

use App\Models\PdvConnection;
use App\Models\PdvOrder;
use App\Models\PdvOrderItem;
use App\Models\PdvOrderPayment;
use App\Pdv\Data\ExternalSaleData;
use App\Pdv\Data\ExternalSaleItemData;
use App\Pdv\Data\ExternalSalePaymentData;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class PdvOrderStagingService
{
    public const NORMALIZATION_VERSION = 1;

    public function __construct(private PdvConnectionAccessService $access) {}

    public function stage(PdvConnection $connection, ExternalSaleData $data): PdvOrder
    {
        $location = $this->access->assertOperationalScope($connection);
        if ($data->externalLocationId !== (string) $location->id) {
            throw new DomainException('O pedido normalizado não pertence à unidade da conexão selecionada.');
        }

        $orderData = $this->orderData($data);
        $itemData = collect($data->items)->map(fn (ExternalSaleItemData $item): array => $this->itemData($item))->all();
        $paymentData = collect($data->payments)->map(fn (ExternalSalePaymentData $payment): array => $this->paymentData($payment))->all();
        $sourceHash = $this->hash([
            'order' => $orderData,
            'items' => collect($itemData)->sortBy('external_item_id')->values()->all(),
            'payments' => collect($paymentData)->sortBy('external_payment_id')->values()->all(),
        ]);

        return DB::transaction(function () use ($connection, $location, $data, $orderData, $itemData, $paymentData, $sourceHash): PdvOrder {
            $seenAt = now();
            $order = PdvOrder::query()
                ->where('pdv_connection_id', $connection->id)
                ->where('external_order_id', $data->externalSaleId)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                $order = new PdvOrder([
                    'pdv_connection_id' => $connection->id,
                    'location_id' => $location->id,
                    'external_order_id' => $data->externalSaleId,
                    'processing_state' => PdvOrder::STATE_STAGED,
                    'first_seen_at' => $seenAt,
                ]);
            } elseif ($order->location_id !== $location->id) {
                throw new DomainException('O pedido staged está vinculado a outra unidade.');
            }

            $changed = $order->exists && $order->source_hash !== $sourceHash;
            if ($changed && $order->processing_state !== PdvOrder::STATE_STAGED) {
                $order->update([
                    'latest_source_hash' => $sourceHash,
                    'last_seen_at' => $seenAt,
                    'source_changed_at' => $seenAt,
                ]);

                return $order->load(['items', 'payments']);
            }

            $order->fill(array_merge($orderData, [
                'source_hash' => $sourceHash,
                'latest_source_hash' => $sourceHash,
                'normalization_version' => self::NORMALIZATION_VERSION,
                'last_seen_at' => $seenAt,
                'source_changed_at' => $changed ? $seenAt : $order->source_changed_at,
            ]));
            $order->save();

            if ($changed) {
                $order->items()->update(['present_in_latest' => false]);
                $order->payments()->update(['present_in_latest' => false]);
            }

            foreach ($itemData as $attributes) {
                $this->stageItem($order, $attributes, $seenAt);
            }
            foreach ($paymentData as $attributes) {
                $this->stagePayment($order, $attributes, $seenAt);
            }

            return $order->load(['items', 'payments']);
        });
    }

    /** @return array<string, mixed> */
    private function orderData(ExternalSaleData $data): array
    {
        return [
            'external_code' => $data->externalOrderNumber,
            'external_status' => $data->status,
            'quantity' => $this->nullableDecimal($data->metadata['reported_quantity'] ?? null, 6),
            'service_total' => $this->nullableDecimal($data->serviceChargeAmount, 2),
            'delivery_total' => $this->nullableDecimal($data->deliveryAmount, 2),
            'subtotal' => $this->decimal($data->grossAmount, 2),
            'discount_total' => $this->decimal($data->discountAmount, 2),
            'total' => $this->decimal($data->netAmount, 2),
            'paid_total' => $this->nullableDecimal($data->paidAmount, 2),
            'change_total' => $this->nullableDecimal($data->changeAmount, 2),
            'external_created_at' => $data->openedAt,
            'external_completed_at' => $data->closedAt,
            'external_updated_at' => $data->updatedAt,
        ];
    }

    /** @return array<string, mixed> */
    private function itemData(ExternalSaleItemData $item): array
    {
        $attributes = [
            'external_item_id' => $item->externalItemId,
            'external_product_id' => $item->externalProductId,
            'external_product_code' => $item->sku,
            'description' => $item->name,
            'quantity' => $this->decimal($item->quantity, 6),
            'unit_price' => $this->nullableDecimal($item->unitPrice, 4),
            'subtotal' => $this->nullableDecimal($item->subtotal, 2),
            'total' => $this->decimal($item->total, 2),
            'external_status' => $item->externalStatus,
            'cancelled' => $item->cancelled,
            'source_details' => ['discount' => $item->discount, 'modifiers' => $item->modifiers, 'notes' => $item->notes],
        ];

        return array_merge($attributes, ['source_hash' => $this->hash($attributes)]);
    }

    /** @return array<string, mixed> */
    private function paymentData(ExternalSalePaymentData $payment): array
    {
        $attributes = [
            'external_payment_id' => $payment->externalPaymentId,
            'external_form_id' => $payment->methodCode,
            'external_form_description' => $payment->methodName,
            'external_type' => $payment->type,
            'amount' => $this->decimal($payment->amount, 2),
            'external_total' => $this->nullableDecimal($payment->externalTotal ?? ($payment->metadata['total'] ?? null), 2),
            'fees' => $this->nullableDecimal($payment->fees ?? ($payment->metadata['fees'] ?? null), 2),
            'installment_number' => $payment->installmentNumber ?? ($payment->metadata['installment_number'] ?? null),
            'installments' => $payment->installments,
            'external_status' => $payment->status,
            'paid_at' => $this->date($payment->paidAt ?? ($payment->metadata['paid_at'] ?? null)),
            'posted_at' => $this->date($payment->postedAt ?? ($payment->metadata['recorded_at'] ?? null)),
            'source_details' => ['brand' => $payment->brand, 'change_amount' => $payment->changeAmount],
        ];

        return array_merge($attributes, ['source_hash' => $this->hash($attributes)]);
    }

    /** @param array<string, mixed> $attributes */
    private function stageItem(PdvOrder $order, array $attributes, mixed $seenAt): void
    {
        unset($attributes['source_details']);
        $item = PdvOrderItem::query()->firstOrNew([
            'pdv_order_id' => $order->id,
            'external_item_id' => $attributes['external_item_id'],
        ]);
        $item->fill(array_merge($attributes, [
            'present_in_latest' => true,
            'first_seen_at' => $item->exists ? $item->first_seen_at : $seenAt,
            'last_seen_at' => $seenAt,
        ]));
        $item->save();
    }

    /** @param array<string, mixed> $attributes */
    private function stagePayment(PdvOrder $order, array $attributes, mixed $seenAt): void
    {
        unset($attributes['source_details']);
        $payment = PdvOrderPayment::query()->firstOrNew([
            'pdv_order_id' => $order->id,
            'external_payment_id' => $attributes['external_payment_id'],
        ]);
        $payment->fill(array_merge($attributes, [
            'present_in_latest' => true,
            'first_seen_at' => $payment->exists ? $payment->first_seen_at : $seenAt,
            'last_seen_at' => $seenAt,
        ]));
        $payment->save();
    }

    private function decimal(string $value, int $scale): string
    {
        return (string) BigDecimal::of($value)->toScale($scale, RoundingMode::HalfUp);
    }

    private function nullableDecimal(mixed $value, int $scale): ?string
    {
        return $value === null || $value === '' ? null : $this->decimal((string) $value, $scale);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value, config('app.timezone', 'America/Sao_Paulo'));
        } catch (\Throwable) {
            throw new DomainException('O pagamento externo contém uma data inválida.');
        }
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($this->canonicalize($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
