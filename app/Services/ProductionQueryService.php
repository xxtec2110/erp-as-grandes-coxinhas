<?php

namespace App\Services;

use App\Agent\AgentPeriodResolver;
use App\Models\Location;
use App\Models\ProductionOrder;
use App\Models\ProductionRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ProductionQueryService
{
    public function __construct(private AuthorizationService $authorization, private AgentPeriodResolver $periods) {}

    /** @return Collection<int, ProductionRecord> */
    public function forDate(Location $location, string $date): Collection
    {
        return ProductionRecord::query()
            ->with(['product', 'location'])
            ->whereBelongsTo($location)
            ->whereDate('operation_date', $date)
            ->orderBy('status')
            ->orderBy('product_id')
            ->get();
    }

    /** @return Collection<int, ProductionOrder> */
    public function orders(User $user, array $filters): Collection
    {
        $locationId = (int) $filters['location_id'];
        $this->authorization->authorize($user, 'production.orders.view', $locationId);
        $period = $this->periods->resolve($filters);

        return ProductionOrder::query()->with(['location', 'items.product'])
            ->where('location_id', $locationId)
            ->whereBetween('production_date', [$period['from']->toDateString(), $period['to']->toDateString()])
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->latest('production_date')->latest('id')->limit(50)->get();
    }
}
