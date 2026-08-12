<?php

namespace App\Services;

use App\Models\Payable;
use App\Models\User;

class CreatePayableService
{
    public function __construct(private AuthorizationService $auth, private FinanceAuditService $audit) {}

    public function create(array $data, User $user, string $source = 'web'): Payable
    {
        $this->auth->authorize($user, 'finance.payables.create', (int) $data['location_id']);
        $existing = Payable::query()->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return $existing;
        }$payable = Payable::query()->create([...$data, 'source' => $source, 'created_by' => $user->id, 'status' => 'pending']);
        $this->audit->record('payable.created', $payable, $user, $payable->toArray(), null, $source, $data['idempotency_key']);

        return $payable;
    }
}
