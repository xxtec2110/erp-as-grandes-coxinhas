<?php

namespace App\Production;

use DomainException;

class ProductionBoardValidationException extends DomainException
{
    public function __construct(public readonly string $reason, string $publicMessage)
    {
        parent::__construct($publicMessage);
    }
}
