<?php

namespace App\Pdv;

use App\Models\PdvConnection;
use App\Pdv\Data\ExternalSaleData;
use App\Pdv\Data\GrandChefGraphqlRequest;
use App\Pdv\Data\PdvPage;
use Carbon\CarbonImmutable;

interface GrandChefQueryContract
{
    public function available(): bool;

    public function connectionRequest(): GrandChefGraphqlRequest;

    public function salesRequest(CarbonImmutable $from, CarbonImmutable $to, ?array $cursor = null): GrandChefGraphqlRequest;

    public function saleRequest(string $externalSaleId): GrandChefGraphqlRequest;

    public function connectionResponseIsValid(array $data): bool;

    public function normalizeSales(PdvConnection $connection, array $data): PdvPage;

    public function normalizeSale(PdvConnection $connection, array $data): ?ExternalSaleData;
}
