<?php

namespace App\Services;

use App\Models\Payable;
use App\Models\User;
use DomainException;

class CancelPayableService
{
    public function __construct(private AuthorizationService $auth, private FinanceAuditService $audit) {}

    public function cancel(Payable $p, User $u): Payable
    {
        $this->auth->authorize($u, 'finance.payables.cancel', $p->location_id);
        if ($p->payments()->exists()) {
            throw new DomainException('Conta com pagamentos não pode ser cancelada.');
        }$old = $p->toArray();
        $p->update(['status' => 'cancelled']);
        $this->audit->record('payable.cancelled', $p, $u, $p->toArray(), $old);

        return $p;
    }
}
