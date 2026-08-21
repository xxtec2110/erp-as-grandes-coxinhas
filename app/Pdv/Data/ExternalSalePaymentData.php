<?php

namespace App\Pdv\Data;

final readonly class ExternalSalePaymentData
{
    public function __construct(
        public string $externalPaymentId,
        public ?string $methodCode,
        public ?string $methodName,
        public ?string $brand,
        public string $amount,
        public ?int $installments = null,
        public ?string $status = null,
        public ?string $type = null,
        public ?string $changeAmount = null,
        public array $metadata = [],
        public ?string $externalTotal = null,
        public ?string $fees = null,
        public ?int $installmentNumber = null,
        public ?string $paidAt = null,
        public ?string $postedAt = null,
    ) {}
}
