<?php

namespace App\Services;

use App\Agent\AgentPeriodResolver;
use App\Enums\StockTransferStatus;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

class StockTransferQueryService
{
    public function __construct(private AuthorizationService $authorization, private AgentPeriodResolver $periods, private ProductMatchService $products) {}

    /** @return Collection<int, StockTransfer> */
    public function list(User $user, int $locationId, string $status = 'recent', array $filters = []): Collection
    {
        $this->authorization->authorize($user, 'transfers.view', $locationId);
        $period = $this->periods->resolve($filters, 'month');
        $productId = null;
        if (filled($filters['product_name'] ?? null)) {
            $matched = $this->products->resolveExactItems([['product_name' => $filters['product_name']]])[0];
            if (! isset($matched['product_id'])) {
                throw new DomainException('Produto não encontrado ou ambíguo no catálogo oficial.');
            }
            $productId = Product::query()->where('active', true)->findOrFail($matched['product_id'])->id;
        }

        return StockTransfer::query()
            ->with(['sourceLocation', 'destinationLocation', 'items.product'])
            ->where(fn ($query) => $query
                ->where('source_location_id', $locationId)
                ->orWhere('destination_location_id', $locationId))
            ->when($status === 'in_transit', fn ($query) => $query->where('status', StockTransferStatus::InTransit))
            ->when($status === 'pending_receipt', fn ($query) => $query
                ->where('destination_location_id', $locationId)
                ->where('status', StockTransferStatus::InTransit))
            ->when(($filters['direction'] ?? null) === 'sent', fn ($query) => $query->where('source_location_id', $locationId))
            ->when(($filters['direction'] ?? null) === 'received', fn ($query) => $query->where('destination_location_id', $locationId))
            ->when($productId !== null, fn ($query) => $query->whereHas('items', fn ($items) => $items->where('product_id', $productId)))
            ->whereBetween('operation_date', [$period['from']->toDateString(), $period['to']->toDateString()])
            ->latest('operation_date')
            ->latest('id')
            ->limit(50)
            ->get();
    }
}
