<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\Payable;
use App\Models\Payment;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class RegisterPaymentService
{
    public function __construct(private AuthorizationService $auth, private FinanceAuditService $audit, private AgentAttachmentService $attachments) {}

    public function register(Payable $payable, array $data, User $user, string $source = 'web'): Payment
    {
        $this->auth->authorize($user, 'finance.payments.create', $payable->location_id);
        if (isset($data['agent_attachment_id'])) {
            $this->attachments->authorizeLink((int) $data['agent_attachment_id'], 'finance', $payable->location_id, $user);
        }

        return DB::transaction(function () use ($payable, $data, $user, $source) {
            $existing = Payment::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing;
            }
            $account = FinancialAccount::query()->findOrFail($data['financial_account_id']);
            if (! $account->active || ($account->location_id !== null && $account->location_id !== $payable->location_id)) {
                throw new DomainException('A conta financeira não pertence à mesma unidade da conta a pagar.');
            }
            $locked = Payable::query()->lockForUpdate()->findOrFail($payable->id);
            $remaining = BigDecimal::of($locked->expected_amount)->minus((string) $locked->payments()->where('status', 'completed')->sum('amount'));
            if (BigDecimal::of($data['amount'])->isGreaterThan($remaining)) {
                throw new DomainException('O pagamento excede o saldo da conta.');
            }$payment = $locked->payments()->create([...$data, 'source' => $source, 'created_by' => $user->id]);
            $paid = BigDecimal::of((string) $locked->payments()->where('status', 'completed')->sum('amount'));
            $locked->update(['status' => $paid->isEqualTo($locked->expected_amount) ? 'paid' : 'partially_paid']);
            $this->audit->record('payment.created', $payment, $user, $payment->toArray(), null, $source, $data['idempotency_key']);

            return $payment;
        });
    }
}
