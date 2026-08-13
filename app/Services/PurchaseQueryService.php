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
}
