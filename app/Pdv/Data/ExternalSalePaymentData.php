<?php

namespace App\Pdv\Data;

final readonly class ExternalSalePaymentData
{
    public function __construct(public string $externalPaymentId, public ?string $methodCode, public ?string $methodName, public ?string $brand, public string $amount, public ?int $installments = null, public ?string $status = null) {}
}
