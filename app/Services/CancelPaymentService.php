<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class CancelPaymentService
{
    public function __construct(private AuthorizationService $auth, private FinanceAuditService $audit) {}

    public function cancel(Payment $payment, User $user, string $reason, string $channel = 'web'): Payment
    {
        return DB::transaction(function () use ($payment, $user, $reason, $channel): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $payable = $payment->payable()->lockForUpdate()->firstOrFail();
            $this->auth->authorize($user, 'finance.payments.cancel', $payable->location_id);
            if ($payment->status === 'cancelled') {
                return $payment;
            }
            $before = $payment->toArray();
            $payment->update(['status' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $paid = BigDecimal::of((string) $payable->payments()->where('status', 'completed')->sum('amount'));
            $payable->update(['status' => $paid->isZero() ? 'open' : ($paid->isEqualTo($payable->expected_amount) ? 'paid' : 'partially_paid')]);
            $this->audit->record('payment.cancelled', $payment, $user, $payment->toArray(), $before, $channel, 'payment-cancel:'.$payment->id);

            return $payment->refresh();
        });
    }
}
