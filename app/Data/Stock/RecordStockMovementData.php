<?php

namespace App\Data\Stock;

use App\Enums\StockMovementType;

final readonly class RecordStockMovementData
{
    public function __construct(
        public int $productId,
        public int $locationId,
        public StockMovementType $type,
        public string $quantityDelta,
        public string $operationDate,
        public string $idempotencyKey,
        public ?int $createdBy = null,
        public ?string $notes = null,
        public ?string $referenceType = null,
        public ?string $referenceId = null,
        public ?int $reversalOfId = null,
    ) {}
}
