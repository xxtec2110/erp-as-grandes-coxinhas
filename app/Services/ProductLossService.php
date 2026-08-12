<?php

namespace App\Services;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\ProductLoss;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProductLossService
{
    public function __construct(
        private StockMovementService $stockMovements,
        private StockBalanceService $stockBalances,
    ) {}

    /** @param array<string, mixed> $data */
    public function record(array $data, ?int $userId): ProductLoss
    {
        return DB::transaction(function () use ($data, $userId): ProductLoss {
            $quantity = BigDecimal::of($data['quantity'])->toScale(6, RoundingMode::Unnecessary);
            $existing = ProductLoss::query()->where('idempotency_key', $data['idempotency_key'])->first();

            if ($existing !== null) {
                $samePayload = $existing->product_id === (int) $data['product_id']
                    && $existing->location_id === (int) $data['location_id']
                    && $existing->loss_reason_id === (int) $data['loss_reason_id']
                    && $existing->quantity === (string) $quantity
                    && $existing->operation_date->toDateString() === $data['operation_date'];

                if (! $samePayload) {
                    throw new DomainException('A chave idempotente já foi usada por outra perda.');
                }

                return $existing;
            }
            Location::query()->whereKey($data['location_id'])->lockForUpdate()->firstOrFail();
            $balance = BigDecimal::of($this->stockBalances->balance($data['product_id'], $data['location_id']));

            if ($balance->isLessThan($quantity)) {
                throw new DomainException('Estoque insuficiente para registrar esta perda.');
            }

            $loss = ProductLoss::query()->create([
                'product_id' => $data['product_id'],
                'location_id' => $data['location_id'],
                'loss_reason_id' => $data['loss_reason_id'],
                'quantity' => (string) $quantity,
                'operation_date' => $data['operation_date'],
                'idempotency_key' => $data['idempotency_key'],
                'created_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->stockMovements->record(new RecordStockMovementData(
                productId: $loss->product_id,
                locationId: $loss->location_id,
                type: StockMovementType::Loss,
                quantityDelta: (string) $quantity->negated(),
                operationDate: $loss->operation_date->toDateString(),
                idempotencyKey: "loss:{$loss->id}:recorded",
                createdBy: $userId,
                notes: "Perda #{$loss->id}: ".($data['notes'] ?? 'sem observação'),
                referenceType: ProductLoss::class,
                referenceId: (string) $loss->id,
            ));

            return $loss->load(['product', 'location', 'reason', 'creator']);
        });
    }
}
