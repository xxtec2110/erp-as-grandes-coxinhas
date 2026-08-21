<?php

namespace App\Pdv;

use App\Models\PdvConnection;
use App\Pdv\Data\ExternalSaleData;
use App\Pdv\Data\PdvPage;
use Carbon\CarbonImmutable;

class GrandChefPdvProvider implements PdvProviderInterface
{
    public function __construct(
        private GrandChefGraphqlClient $client,
        private GrandChefQueryContract $contract,
    ) {}

    public function capabilities(): array
    {
        $capabilities = array_fill_keys(['sales', 'sale', 'products', 'payment_methods', 'locations', 'cancellations', 'updated_sales', 'webhook'], PdvCapability::Unknown->value);
        if ($this->contract->available()) {
            $capabilities['sales'] = PdvCapability::Supported->value;
            $capabilities['sale'] = PdvCapability::Supported->value;
        }

        return $capabilities;
    }

    public function health(PdvConnection $connection): string
    {
        return $connection->status;
    }

    public function testConnection(PdvConnection $connection): bool
    {
        $data = $this->client->execute($connection, $this->contract->connectionRequest());

        return $this->contract->connectionResponseIsValid($data);
    }

    public function fetchSales(PdvConnection $connection, ?array $cursor = null, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): PdvPage
    {
        if ($from === null || $to === null) {
            throw new IntegrationNotConfiguredException('A consulta GrandChef exige um período explícito.');
        }

        $data = $this->client->execute($connection, $this->contract->salesRequest($from, $to, $cursor));
        $page = $this->contract->normalizeSales($connection, $data);

        if (! ($page->metadata['requires_detail_fetch'] ?? false)) {
            return $page;
        }

        $sales = [];
        foreach ($page->items as $sale) {
            $detail = $this->fetchSale($connection, $sale->externalSaleId);
            if ($detail === null) {
                throw new GrandChefRequestException('incomplete_response', 'O GrandChef não retornou o detalhe de um pedido listado.');
            }
            $sales[] = $detail;
        }

        return new PdvPage(
            $sales,
            $page->nextCursor,
            $page->reportedTotal,
            array_merge($page->metadata, ['details_fetched' => count($sales)]),
        );
    }

    public function fetchSale(PdvConnection $connection, string $externalSaleId): ?ExternalSaleData
    {
        $data = $this->client->execute($connection, $this->contract->saleRequest($externalSaleId));

        return $this->contract->normalizeSale($connection, $data);
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
