<?php

namespace App\Pdv\Data;

final readonly class ExternalSaleItemData
{
    public function __construct(
        public string $externalItemId,
        public ?string $externalProductId,
        public ?string $sku,
        public string $name,
        public string $quantity,
        public string $unitPrice,
        public string $discount,
        public string $total,
        public array $modifiers = [],
        public ?string $notes = null,
        public ?string $subtotal = null,
        public ?string $externalStatus = null,
        public bool $cancelled = false,
    ) {}
}
