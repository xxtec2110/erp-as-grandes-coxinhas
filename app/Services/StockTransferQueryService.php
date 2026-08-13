<?php

namespace App\Services;

use App\Enums\StockTransferStatus;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class StockTransferQueryService
{
    public function __construct(private AuthorizationService $authorization) {}

    /** @return Collection<int, StockTransfer> */
    public function list(User $user, int $locationId, string $status = 'recent'): Collection
    {
        $this->authorization->authorize($user, 'transfers.view', $locationId);

        return StockTransfer::query()
            ->with(['sourceLocation', 'destinationLocation', 'items.product'])
            ->where(fn ($query) => $query
                ->where('source_location_id', $locationId)
                ->orWhere('destination_location_id', $locationId))
            ->when($status === 'in_transit', fn ($query) => $query->where('status', StockTransferStatus::InTransit))
            ->when($status === 'pending_receipt', fn ($query) => $query
                ->where('destination_location_id', $locationId)
                ->where('status', StockTransferStatus::InTransit))
            ->latest('operation_date')
            ->latest('id')
            ->limit(10)
            ->get();
    }
}
