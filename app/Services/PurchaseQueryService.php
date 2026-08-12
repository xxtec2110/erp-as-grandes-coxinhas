<?php

namespace App\Services;

use App\Models\PurchaseDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class PurchaseQueryService
{
    public function __construct(private AuthorizationService $authorization) {}

    public function documents(User $user): Collection
    {
        return PurchaseDocument::query()->with(['supplier', 'location'])->whereIn('location_id', $this->authorization->accessibleLocations($user)->pluck('id'))->latest('issue_date')->get();
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
