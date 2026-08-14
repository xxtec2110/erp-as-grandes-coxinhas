<?php

namespace App\Pdv;

use App\Models\PdvConnection;
use App\Pdv\Data\ExternalSaleData;
use App\Pdv\Data\PdvPage;
use Carbon\CarbonImmutable;

interface PdvProviderInterface
{
    public function capabilities(): array;

    public function health(PdvConnection $connection): string;

    public function testConnection(PdvConnection $connection): bool;

    public function fetchSales(PdvConnection $connection, ?array $cursor = null, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): PdvPage;

    public function fetchSale(PdvConnection $connection, string $externalSaleId): ?ExternalSaleData;

    public function fetchProducts(PdvConnection $connection, ?array $cursor = null): array;

    public function fetchPaymentMethods(PdvConnection $connection): array;

    public function fetchLocations(PdvConnection $connection): array;

    public function fetchCancellations(PdvConnection $connection, ?array $cursor = null): PdvPage;

    public function fetchUpdatedSales(PdvConnection $connection, ?array $cursor = null): PdvPage;

    public function normalizeWebhook(PdvConnection $connection, array $payload): ExternalSaleData;
}
