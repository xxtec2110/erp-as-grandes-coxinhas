<?php

namespace App\Pdv;

use App\Models\PdvConnection;
use App\Pdv\Data\ExternalSaleData;
use App\Pdv\Data\PdvPage;
use Carbon\CarbonImmutable;

class GrandChefPdvProvider implements PdvProviderInterface
{
    public function capabilities(): array
    {
        return array_fill_keys(['sales', 'sale', 'products', 'payment_methods', 'locations', 'cancellations', 'updated_sales', 'webhook'], PdvCapability::Unknown->value);
    }

    public function health(PdvConnection $connection): string
    {
        return 'not_configured';
    }

    public function testConnection(PdvConnection $connection): bool
    {
        throw new IntegrationNotConfiguredException('Integração ainda não configurada.');
    }

    public function fetchSales(PdvConnection $connection, ?array $cursor = null, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): PdvPage
    {
        throw $this->missing();
    }

    public function fetchSale(PdvConnection $connection, string $externalSaleId): ?ExternalSaleData
    {
        throw $this->missing();
    }

    public function fetchProducts(PdvConnection $connection, ?array $cursor = null): array
    {
        throw $this->missing();
    }

    public function fetchPaymentMethods(PdvConnection $connection): array
    {
        throw $this->missing();
    }

    public function fetchLocations(PdvConnection $connection): array
    {
        throw $this->missing();
    }

    public function fetchCancellations(PdvConnection $connection, ?array $cursor = null): PdvPage
    {
        throw $this->missing();
    }

    public function fetchUpdatedSales(PdvConnection $connection, ?array $cursor = null): PdvPage
    {
        throw $this->missing();
    }

    public function normalizeWebhook(PdvConnection $connection, array $payload): ExternalSaleData
    {
        throw $this->missing();
    }

    private function missing(): IntegrationNotConfiguredException
    {
        return new IntegrationNotConfiguredException('A documentação oficial do GrandChef ainda é necessária.');
    }
}
