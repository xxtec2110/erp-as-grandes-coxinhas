<?php

namespace App\Pdv;

use App\Models\PdvConnection;
use App\Pdv\Data\ExternalSaleData;
use App\Pdv\Data\PdvPage;
use Carbon\CarbonImmutable;

class FakePdvProvider implements PdvProviderInterface
{
    /** @var array<int, ExternalSaleData> */
    private array $sales = [];

    public function setSales(array $sales): void
    {
        $this->sales = $sales;
    }

    public function capabilities(): array
    {
        return array_fill_keys(['sales', 'sale', 'products', 'payment_methods', 'locations', 'cancellations', 'updated_sales', 'webhook'], PdvCapability::Supported->value);
    }

    public function health(PdvConnection $connection): string
    {
        return 'healthy';
    }

    public function testConnection(PdvConnection $connection): bool
    {
        return true;
    }

    public function fetchSales(PdvConnection $connection, ?array $cursor = null, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): PdvPage
    {
        return new PdvPage($this->sales);
    }

    public function fetchSale(PdvConnection $connection, string $externalSaleId): ?ExternalSaleData
    {
        return collect($this->sales)->first(fn ($sale) => $sale->externalSaleId === $externalSaleId);
    }

    public function fetchProducts(PdvConnection $connection, ?array $cursor = null): array
    {
        return [];
    }

    public function fetchPaymentMethods(PdvConnection $connection): array
    {
        return [];
    }

    public function fetchLocations(PdvConnection $connection): array
    {
        return [];
    }

    public function fetchCancellations(PdvConnection $connection, ?array $cursor = null): PdvPage
    {
        return new PdvPage(array_values(array_filter($this->sales, fn ($sale) => in_array($sale->status, ['cancelled', 'voided', 'refunded'], true))));
    }

    public function fetchUpdatedSales(PdvConnection $connection, ?array $cursor = null): PdvPage
    {
        return new PdvPage($this->sales);
    }

    public function normalizeWebhook(PdvConnection $connection, array $payload): ExternalSaleData
    {
        return $payload['normalized_sale'];
    }
}
