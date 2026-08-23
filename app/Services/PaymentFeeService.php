<?php

namespace App\Services;

use App\Models\PaymentFee;
use App\Models\PaymentFeeAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PaymentFeeService
{
    /** @param array<string, mixed> $data */
    public function apply(array $data, User $user, string $source = 'web', ?int $importId = null): PaymentFee
    {
        return DB::transaction(function () use ($data, $user, $source, $importId): PaymentFee {
            $cardBrandId = $data['card_brand_id'] ?? null;
            $query = PaymentFee::query()->where('acquirer_id', $data['acquirer_id'])->where('card_brand_id', $cardBrandId)->where('payment_method', $data['payment_method'])->where(fn ($query) => empty($data['installments']) ? $query->whereNull('installments') : $query->where('installments', $data['installments']));
            $previous = (clone $query)->where('is_current', true)->lockForUpdate()->first();
            if ($previous !== null) {
                $previous->update(['is_current' => false, 'effective_until' => now()->parse($data['effective_from'])->subDay()->toDateString()]);
            }
            $fee = PaymentFee::query()->create([...$data, 'card_brand_id' => $cardBrandId, 'installments' => $data['installments'] ?: null, 'is_current' => true, 'active' => true, 'source' => $source, 'created_by' => $user->id, 'payment_fee_import_id' => $importId]);
            PaymentFeeAudit::query()->create(['payment_fee_id' => $fee->id, 'user_id' => $user->id, 'acquirer_id' => $fee->acquirer_id, 'card_brand_id' => $fee->card_brand_id, 'payment_method' => $fee->payment_method, 'installments' => $fee->installments, 'previous_value' => $previous?->only(['id', 'fee_percentage', 'fixed_fee', 'effective_from', 'effective_until']), 'new_value' => $fee->only(['id', 'fee_percentage', 'fixed_fee', 'effective_from', 'effective_until']), 'source' => $source, 'payment_fee_import_id' => $importId]);

            return $fee;
        });
    }
}
