<?php

namespace App\Services;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Enums\StockTransferStatus;
use App\Models\Location;
use App\Models\StockTransfer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class StockTransferService
{
    public function __construct(
        private StockMovementService $stockMovements,
        private StockBalanceService $stockBalances,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?int $userId): StockTransfer
    {
        return DB::transaction(function () use ($data, $userId): StockTransfer {
            $existing = StockTransfer::query()->where('idempotency_key', $data['idempotency_key'])->first();

            if ($existing !== null) {
                $existing->loadMissing('items');
                $item = $existing->items->sole();
                $samePayload = $existing->source_location_id === (int) $data['source_location_id']
                    && $existing->destination_location_id === (int) $data['destination_location_id']
                    && $existing->operation_date->toDateString() === $data['operation_date']
                    && $item->product_id === (int) $data['product_id']
                    && $item->quantity_sent === (string) BigDecimal::of($data['quantity'])->toScale(6);

                if (! $samePayload) {
                    throw new DomainException('A chave idempotente já foi usada por outra transferência.');
                }

                return $existing;
            }

            if ((int) $data['source_location_id'] === (int) $data['destination_location_id']) {
                throw new DomainException('A origem e o destino devem ser diferentes.');
            }

            $transfer = StockTransfer::query()->create([
                'source_location_id' => $data['source_location_id'],
                'destination_location_id' => $data['destination_location_id'],
                'status' => StockTransferStatus::Pending,
                'operation_date' => $data['operation_date'],
                'idempotency_key' => $data['idempotency_key'],
                'created_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            $transfer->items()->create([
                'product_id' => $data['product_id'],
                'quantity_sent' => (string) BigDecimal::of($data['quantity'])->toScale(6, RoundingMode::Unnecessary),
            ]);

            return $transfer->load(['items.product', 'sourceLocation', 'destinationLocation']);
        });
    }

    public function dispatch(StockTransfer $transfer, string $dispatchDate, ?int $userId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $dispatchDate, $userId): StockTransfer {
            $transfer = StockTransfer::query()->with('items.product')->lockForUpdate()->findOrFail($transfer->id);

            if ($transfer->status === StockTransferStatus::InTransit || $transfer->status === StockTransferStatus::Received) {
                if ($transfer->dispatched_date?->toDateString() !== $dispatchDate) {
                    throw new DomainException('A transferência já foi expedida em outra data.');
                }

                return $transfer;
            }

            if ($transfer->status !== StockTransferStatus::Pending) {
                throw new DomainException('Somente uma transferência pendente pode ser expedida.');
            }

            Location::query()->whereKey($transfer->source_location_id)->lockForUpdate()->firstOrFail();

            foreach ($transfer->items as $item) {
                $balance = BigDecimal::of($this->stockBalances->balance($item->product_id, $transfer->source_location_id));
                $quantity = BigDecimal::of($item->quantity_sent);

                if ($balance->isLessThan($quantity)) {
                    throw new DomainException("Estoque insuficiente para expedir {$item->product->name}.");
                }
            }

            foreach ($transfer->items as $item) {
                $this->stockMovements->record(new RecordStockMovementData(
                    productId: $item->product_id,
                    locationId: $transfer->source_location_id,
                    type: StockMovementType::TransferOut,
                    quantityDelta: (string) BigDecimal::of($item->quantity_sent)->negated(),
                    operationDate: $dispatchDate,
                    idempotencyKey: "transfer:{$transfer->id}:item:{$item->id}:dispatched",
                    createdBy: $userId,
                    notes: "Transferência #{$transfer->id} expedida.",
                    referenceType: StockTransfer::class,
                    referenceId: (string) $transfer->id,
                ));
            }

            $transfer->update([
                'status' => StockTransferStatus::InTransit,
                'dispatched_date' => $dispatchDate,
                'dispatched_by' => $userId,
                'dispatched_at' => now(),
            ]);

            return $transfer->refresh()->load('items.product');
        });
    }

    /** @param array<int, string> $receivedQuantities */
    public function receive(StockTransfer $transfer, string $receivedDate, array $receivedQuantities, ?int $userId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $receivedDate, $receivedQuantities, $userId): StockTransfer {
            $transfer = StockTransfer::query()->with('items.product')->lockForUpdate()->findOrFail($transfer->id);

            if ($transfer->status === StockTransferStatus::Received) {
                $sameReceipt = $transfer->received_date?->toDateString() === $receivedDate;

                foreach ($transfer->items as $item) {
                    $sameReceipt = $sameReceipt
                        && array_key_exists($item->id, $receivedQuantities)
                        && $item->quantity_received === (string) BigDecimal::of($receivedQuantities[$item->id])->toScale(6);
                }

                if (! $sameReceipt) {
                    throw new DomainException('A transferência já foi recebida com outros dados.');
                }

                return $transfer;
            }

            if ($transfer->status !== StockTransferStatus::InTransit) {
                throw new DomainException('Somente uma transferência em trânsito pode ser recebida.');
            }

            foreach ($transfer->items as $item) {
                if (! array_key_exists($item->id, $receivedQuantities)) {
                    throw new DomainException("Informe a quantidade recebida de {$item->product->name}.");
                }

                $quantity = BigDecimal::of($receivedQuantities[$item->id])->toScale(6, RoundingMode::Unnecessary);

                if ($quantity->isNegative()) {
                    throw new DomainException('A quantidade recebida não pode ser negativa.');
                }

                if (! $quantity->isZero()) {
                    $this->stockMovements->record(new RecordStockMovementData(
                        productId: $item->product_id,
                        locationId: $transfer->destination_location_id,
                        type: StockMovementType::TransferIn,
                        quantityDelta: (string) $quantity,
                        operationDate: $receivedDate,
                        idempotencyKey: "transfer:{$transfer->id}:item:{$item->id}:received",
                        createdBy: $userId,
                        notes: "Transferência #{$transfer->id} recebida.",
                        referenceType: StockTransfer::class,
                        referenceId: (string) $transfer->id,
                    ));
                }

                $item->update(['quantity_received' => (string) $quantity]);
            }

            $transfer->update([
                'status' => StockTransferStatus::Received,
                'received_date' => $receivedDate,
                'received_by' => $userId,
                'received_at' => now(),
            ]);

            return $transfer->refresh()->load('items.product');
        });
    }

    public function cancel(StockTransfer $transfer, ?int $userId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $userId): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($transfer->status === StockTransferStatus::Cancelled) {
                return $transfer;
            }

            if ($transfer->status !== StockTransferStatus::Pending) {
                throw new DomainException('Somente uma transferência pendente pode ser cancelada.');
            }

            $transfer->update([
                'status' => StockTransferStatus::Cancelled,
                'cancelled_by' => $userId,
                'cancelled_at' => now(),
            ]);

            return $transfer->refresh();
        });
    }
}
