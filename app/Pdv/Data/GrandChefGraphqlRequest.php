<?php

namespace App\Pdv\Data;

final readonly class GrandChefGraphqlRequest
{
    public function __construct(
        public string $query,
        public array $variables = [],
        public ?string $operationName = null,
    ) {}
}
