<?php

namespace App\Services;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\ProductionStatus;
use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\ProductionRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProductionService
{
    public function __construct(private StockMovementService $stockMovements) {}

    /** @param array<string, mixed> $data */
    public function plan(array $data, ?int $userId): ProductionRecord
    {
        return DB::transaction(function () use ($data, $userId): ProductionRecord {
            $existing = ProductionRecord::query()->where('idempotency_key', $data['idempotency_key'])->first();

            if ($existing !== null) {
                $samePayload = $existing->product_id === (int) $data['product_id']
                    && $existing->location_id === (int) $data['location_id']
                    && $existing->planned_quantity === (string) BigDecimal::of($data['planned_quantity'])->toScale(6)
                    && $existing->operation_date->toDateString() === $data['operation_date'];

                if (! $samePayload) {
                    throw new DomainException('A chave idempotente já foi usada por outro planejamento.');
                }

                return $existing;
            }

            $location = Location::query()->findOrFail($data['location_id']);

            if ($location->type !== Location::TYPE_PRODUCTION) {
                throw new DomainException('A produção deve ser registrada em uma unidade do tipo produção.');
            }

            return ProductionRecord::query()->create([
                'product_id' => $data['product_id'],
                'location_id' => $location->id,
                'planned_quantity' => (string) BigDecimal::of($data['planned_quantity'])->toScale(6, RoundingMode::Unnecessary),
                'operation_date' => $data['operation_date'],
                'status' => ProductionStatus::Planned,
                'idempotency_key' => $data['idempotency_key'],
                'created_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    public function complete(ProductionRecord $production, string $actualQuantity, ?int $userId): ProductionRecord
    {
        return DB::transaction(function () use ($production, $actualQuantity, $userId): ProductionRecord {
            $production = ProductionRecord::query()->lockForUpdate()->findOrFail($production->id);
            $quantity = BigDecimal::of($actualQuantity)->toScale(6, RoundingMode::Unnecessary);

            if ($production->status === ProductionStatus::Completed) {
                if ($production->actual_quantity !== (string) $quantity) {
                    throw new DomainException('A produção já foi concluída com outra quantidade.');
                }

                return $production;
            }

            if ($production->status !== ProductionStatus::Planned) {
                throw new DomainException('Somente uma produção planejada pode ser concluída.');
            }

            if ($quantity->isLessThanOrEqualTo(0)) {
                throw new DomainException('A quantidade produzida deve ser maior que zero.');
            }

            $this->stockMovements->record(new RecordStockMovementData(
                productId: $production->product_id,
                locationId: $production->location_id,
                type: StockMovementType::Production,
                quantityDelta: (string) $quantity,
                operationDate: $production->operation_date->toDateString(),
                idempotencyKey: "production:{$production->id}:completed",
                createdBy: $userId,
                notes: "Produção #{$production->id} concluída.",
                referenceType: ProductionRecord::class,
                referenceId: (string) $production->id,
            ));

            $production->update([
                'actual_quantity' => (string) $quantity,
                'status' => ProductionStatus::Completed,
                'completed_by' => $userId,
                'completed_at' => now(),
            ]);

            return $production->refresh();
        });
    }

    public function cancel(ProductionRecord $production): ProductionRecord
    {
        return DB::transaction(function () use ($production): ProductionRecord {
            $production = ProductionRecord::query()->lockForUpdate()->findOrFail($production->id);

            if ($production->status === ProductionStatus::Cancelled) {
                return $production;
            }

            if ($production->status !== ProductionStatus::Planned) {
                throw new DomainException('Uma produção concluída não pode ser cancelada.');
            }

            $production->update(['status' => ProductionStatus::Cancelled]);

            return $production->refresh();
        });
    }
}
