<?php

namespace App\Production;

use Carbon\CarbonImmutable;

final readonly class ProductionBoardInterpretation
{
    public function __construct(public ?CarbonImmutable $operationDate, public array $items, public string $confidence, public array $errors = []) {}

    public function validFor(CarbonImmutable $date): bool
    {
        return $this->operationDate?->isSameDay($date) === true && $this->errors === [] && $this->items !== [] && collect($this->items)->every(fn ($i) => isset($i['product_id'],$i['quantity']) && filter_var($i['quantity'], FILTER_VALIDATE_INT) !== false && (int) $i['quantity'] > 0);
    }
}
