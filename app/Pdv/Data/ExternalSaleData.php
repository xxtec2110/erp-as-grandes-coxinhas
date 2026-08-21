<?php

namespace App\Pdv\Data;

use Carbon\CarbonImmutable;

final readonly class ExternalSaleData
{
    /** @param array<int, ExternalSaleItemData> $items @param array<int, ExternalSalePaymentData> $payments */
    public function __construct(public string $provider, public string $externalSaleId, public ?string $externalOrderNumber, public string $externalLocationId, public string $status, public ?CarbonImmutable $openedAt, public CarbonImmutable $closedAt, public CarbonImmutable $updatedAt, public string $grossAmount, public string $discountAmount, public string $serviceChargeAmount, public string $deliveryAmount, public string $netAmount, public array $items, public array $payments = [], public ?string $customerReference = null, public array $metadata = [], public ?string $paidAmount = null, public ?string $changeAmount = null) {}
}
