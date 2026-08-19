<?php

namespace App\Services;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class StockMovementService
{
    public function record(RecordStockMovementData $data): StockMovement
    {
        $quantity = BigDecimal::of($data->quantityDelta)->toScale(6, RoundingMode::Unnecessary);

        if ($quantity->isZero()) {
            throw new DomainException('A quantidade do movimento não pode ser zero.');
        }

        try {
            return DB::transaction(function () use ($data, $quantity): StockMovement {
                Location::query()->whereKey($data->locationId)->lockForUpdate()->firstOrFail();

                $existing = StockMovement::query()
                    ->where('idempotency_key', $data->idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return $this->assertSameMovement($existing, $data, (string) $quantity);
                }

                if ($data->type === StockMovementType::OpeningBalance
                    && StockMovement::query()
                        ->where('product_id', $data->productId)
                        ->where('location_id', $data->locationId)
                        ->exists()) {
                    throw new DomainException('Este produto já possui histórico nesta unidade. Registre um ajuste auditável em vez de outro estoque inicial.');
                }

                return StockMovement::query()->create([
                    'product_id' => $data->productId,
                    'location_id' => $data->locationId,
                    'type' => $data->type,
                    'quantity_delta' => (string) $quantity,
                    'operation_date' => $data->operationDate,
                    'idempotency_key' => $data->idempotencyKey,
                    'created_by' => $data->createdBy,
                    'notes' => $data->notes,
                    'reference_type' => $data->referenceType,
                    'reference_id' => $data->referenceId,
                    'reversal_of_id' => $data->reversalOfId,
                ]);
            });
        } catch (QueryException $exception) {
            $existing = StockMovement::query()
                ->where('idempotency_key', $data->idempotencyKey)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return $this->assertSameMovement($existing, $data, (string) $quantity);
        }
    }

    private function assertSameMovement(
        StockMovement $movement,
        RecordStockMovementData $data,
        string $quantity,
    ): StockMovement {
        $samePayload = $movement->product_id === $data->productId
            && $movement->location_id === $data->locationId
            && $movement->type === $data->type
            && $movement->quantity_delta === $quantity
            && $movement->operation_date->toDateString() === $data->operationDate;

        if (! $samePayload) {
            throw new DomainException('A chave idempotente já foi usada por outro movimento.');
        }

        return $movement;
    }
}
