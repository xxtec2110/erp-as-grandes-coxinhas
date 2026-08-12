<?php

namespace App\Services;

use App\Models\PaymentFee;

class PaymentFeeResolver
{
    public function resolve(int $acquirerId, int $cardBrandId, string $method, ?int $installments, string $operationDate): ?PaymentFee
    {
        return PaymentFee::query()
            ->where('acquirer_id', $acquirerId)
            ->where('card_brand_id', $cardBrandId)
            ->where('payment_method', $method)
            ->where('active', true)
            ->whereDate('effective_from', '<=', $operationDate)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $operationDate))
            ->when($installments !== null, fn ($query) => $query->where(fn ($query) => $query->where('installments', $installments)->orWhereNull('installments'))->orderByRaw('installments IS NULL'))
            ->when($installments === null, fn ($query) => $query->whereNull('installments'))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }
}
