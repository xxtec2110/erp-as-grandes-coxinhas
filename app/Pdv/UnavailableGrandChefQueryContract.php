<?php

namespace App\Pdv;

use App\Models\PdvConnection;
use App\Pdv\Data\ExternalSaleData;
use App\Pdv\Data\GrandChefGraphqlRequest;
use App\Pdv\Data\PdvPage;
use Carbon\CarbonImmutable;

class UnavailableGrandChefQueryContract implements GrandChefQueryContract
{
    public function available(): bool
    {
        return false;
    }

    public function connectionRequest(): GrandChefGraphqlRequest
    {
        throw $this->missing();
    }

    public function salesRequest(CarbonImmutable $from, CarbonImmutable $to, ?array $cursor = null): GrandChefGraphqlRequest
    {
        throw $this->missing();
    }

    public function saleRequest(string $externalSaleId): GrandChefGraphqlRequest
    {
        throw $this->missing();
    }

    public function connectionResponseIsValid(array $data): bool
    {
        return false;
    }

    public function normalizeSales(PdvConnection $connection, array $data): PdvPage
    {
        throw $this->missing();
    }

    public function normalizeSale(PdvConnection $connection, array $data): ?ExternalSaleData
    {
        throw $this->missing();
    }

    private function missing(): IntegrationNotConfiguredException
    {
        return new IntegrationNotConfiguredException('Contrato GraphQL validado do GrandChef ainda não foi fornecido.');
    }
}
