<?php

namespace App\Services;

use App\Agent\AgentPeriodResolver;
use App\Models\PurchaseDocument;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;

class PurchaseQueryService
{
    public function __construct(private AuthorizationService $authorization, private AgentPeriodResolver $periods) {}

    public function documents(User $user, ?int $locationId = null, array $filters = []): Collection
    {
        $locations = $this->authorization->accessibleLocations($user)->pluck('id');
        if ($locationId !== null) {
            $this->authorization->authorize($user, 'purchases.view', $locationId);
        }

        return PurchaseDocument::query()
            ->with(['supplier', 'location'])
            ->whereIn('location_id', $locations)
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('receipt_status', $filters['status']))
            ->when(filled($filters['supplier_name'] ?? null), fn ($query) => $query->whereHas('supplier', fn ($supplier) => $supplier->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower((string) $filters['supplier_name']).'%'])))
            ->when(isset($filters['from']), fn ($query) => $query->whereDate('issue_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->whereDate('issue_date', '<=', $filters['to']))
            ->latest('issue_date')
            ->limit(50)
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

    /** @return array<string, mixed> */
    public function summary(User $user, array $filters = []): array
    {
        $period = $this->periods->resolve($filters, 'month');
        $filters['from'] = $period['from']->toDateString();
        $filters['to'] = $period['to']->toDateString();
        $documents = $this->documents($user, isset($filters['location_id']) ? (int) $filters['location_id'] : null, $filters);
        $total = $documents->reduce(
            fn (BigDecimal $sum, PurchaseDocument $document): BigDecimal => $sum->plus($document->total_amount),
            BigDecimal::zero(),
        );

        return [
            'period' => ['from' => $filters['from'], 'to' => $filters['to']],
            'count' => $documents->count(),
            'total' => (string) $total,
            'pending_receipt' => $documents->whereNotIn('receipt_status', ['received'])->count(),
            'partially_received' => $documents->where('receipt_status', 'partially_received')->count(),
            'received' => $documents->where('receipt_status', 'received')->count(),
        ];
    }
}
