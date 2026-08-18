<?php

namespace App\Services;

use App\Models\PurchaseDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class PurchaseQueryService
{
    public function __construct(private AuthorizationService $authorization) {}

    public function documents(User $user, ?int $locationId = null): Collection
    {
        $locations = $this->authorization->accessibleLocations($user)->pluck('id');
        if ($locationId !== null) {
            $this->authorization->authorize($user, 'purchases.view', $locationId);
        }

        return PurchaseDocument::query()
            ->with(['supplier', 'location'])
            ->whereIn('location_id', $locations)
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->latest('issue_date')
            ->limit(10)
            ->get();
    }

    public function document(User $user, int $id): PurchaseDocument
    {
        $document = PurchaseDocument::query()->with(['supplier', 'location', 'items'])->findOrFail($id);
        $this->authorization->authorize($user, 'purchases.view', $document->location_id);

        return $document;
    }

    public function items(User $user, int $documentId): Collection
    {
        return $this->document($user, $documentId)->items;
    }

    public function history(User $user, array $filters = []): Collection
    {
        $locations = $this->authorization->accessibleLocations($user)->pluck('id');
        if (isset($filters['location_id'])) {
            $this->authorization->authorize($user, 'purchases.view', (int) $filters['location_id']);
        }

        return PurchaseDocument::query()->with(['supplier', 'location', 'items.ingredient'])
            ->whereIn('location_id', $locations)
            ->when(isset($filters['location_id']), fn ($query) => $query->where('location_id', $filters['location_id']))
            ->when(isset($filters['supplier_id']), fn ($query) => $query->where('supplier_id', $filters['supplier_id']))
            ->when(isset($filters['ingredient_id']), fn ($query) => $query->whereHas('items', fn ($items) => $items->where('ingredient_id', $filters['ingredient_id'])))
            ->when(isset($filters['start_date']), fn ($query) => $query->whereDate('issue_date', '>=', $filters['start_date']))
            ->when(isset($filters['end_date']), fn ($query) => $query->whereDate('issue_date', '<=', $filters['end_date']))
            ->latest('issue_date')->limit(50)->get();
    }
}
