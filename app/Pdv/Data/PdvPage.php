<?php

namespace App\Pdv\Data;

final readonly class PdvPage
{
    /** @param array<int, ExternalSaleData> $items */
    public function __construct(
        public array $items,
        public ?array $nextCursor = null,
        public ?int $reportedTotal = null,
        public array $metadata = [],
    ) {}
}
