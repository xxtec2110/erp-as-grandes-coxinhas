<?php

namespace App\Pdv;

use DomainException;

class PdvOrderImportBlockedException extends DomainException
{
    /** @param array<int,array<string,mixed>> $blockers */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct(collect($blockers)->pluck('message')->implode(' '));
    }
}
