<?php

namespace App\Services;

use App\Models\Payable;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class FinanceQueryService
{
    public function __construct(private AuthorizationService $authorization) {}

    /** @return Collection<int, Payable> */
    public function payables(User $user, array $filters = []): Collection
    {
        $locations = $this->authorization->accessibleLocations($user);
        $query = Payable::query()->with(['supplier', 'location', 'payments'])->whereIn('location_id', $locations->pluck('id'));
        if (isset($filters['location_id'])) {
            $this->authorization->authorize($user, 'finance.payables.view', (int) $filters['location_id']);
            $query->where('location_id', $filters['location_id']);
        }
        $period = $filters['period'] ?? 'open';
        $query->when($period === 'open', fn ($q) => $q->whereNotIn('status', ['paid', 'cancelled']))
            ->when($period === 'overdue', fn ($q) => $q->whereDate('due_date', '<', now())->whereNotIn('status', ['paid', 'cancelled']))
            ->when($period === 'today', fn ($q) => $q->whereDate('due_date', now()))
            ->when($period === 'week', fn ($q) => $q->whereBetween('due_date', [now()->startOfWeek(), now()->endOfWeek()]))
            ->when(isset($filters['supplier']), fn ($q) => $q->whereHas('supplier', fn ($s) => $s->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($filters['supplier']).'%'])));

        return $query->orderBy('due_date')->get();
    }

    public function payable(User $user, int $id): Payable
    {
        $payable = Payable::query()->with(['supplier', 'location', 'payments'])->findOrFail($id);
        $this->authorization->authorize($user, 'finance.payables.view', $payable->location_id);

        return $payable;
    }

    /** @return Collection<int, Payment> */
    public function payments(User $user, array $filters = []): Collection
    {
        $ids = $this->authorization->accessibleLocations($user)->pluck('id');

        return Payment::query()->completed()->with(['payable.supplier', 'financialAccount'])->whereHas('payable', fn ($q) => $q->whereIn('location_id', $ids))->when(isset($filters['supplier']), fn ($q) => $q->whereHas('payable.supplier', fn ($s) => $s->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($filters['supplier']).'%'])))->when(isset($filters['account']), fn ($q) => $q->whereHas('financialAccount', fn ($a) => $a->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($filters['account']).'%'])))->when(isset($filters['payer']), fn ($q) => $q->whereRaw('LOWER(paid_by_name) LIKE ?', ['%'.mb_strtolower($filters['payer']).'%']))->get();
    }
}
