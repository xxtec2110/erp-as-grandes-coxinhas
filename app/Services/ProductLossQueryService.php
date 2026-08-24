<?php

namespace App\Services;

use App\Agent\AgentPeriodResolver;
use App\Models\ProductLoss;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ProductLossQueryService
{
    public function __construct(private AuthorizationService $authorization, private AgentPeriodResolver $periods) {}

    /** @return Collection<int, ProductLoss> */
    public function query(User $user, array $filters): Collection
    {
        $locationId = (int) $filters['location_id'];
        $this->authorization->authorize($user, 'losses.view', $locationId);
        $period = $this->periods->resolve($filters);

        return ProductLoss::query()->with(['product', 'location', 'reason'])
            ->where('location_id', $locationId)
            ->whereBetween('operation_date', [$period['from']->toDateString(), $period['to']->toDateString()])
            ->when(isset($filters['product_id']), fn ($query) => $query->where('product_id', $filters['product_id']))
            ->when(filled($filters['reason'] ?? null), fn ($query) => $query->whereHas('reason', fn ($reason) => $reason->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower((string) $filters['reason']).'%'])))
            ->latest('operation_date')->latest('id')->limit(50)->get();
    }
}
