<?php

namespace App\Services;

use App\Models\PdvConnection;
use App\Models\PdvIntegrationEvent;
use App\Models\PdvOrder;
use App\Models\PdvSyncCheckpoint;
use Illuminate\Support\Collection;

class PdvHealthService
{
    public function __construct(private PdvOrderReconciliationService $reconciliation) {}

    /** @param Collection<int, PdvConnection> $connections @return Collection<int, array<string, mixed>> */
    public function forConnections(Collection $connections): Collection
    {
        $ids = $connections->pluck('id');
        $orders = PdvOrder::query()->whereIn('pdv_connection_id', $ids)
            ->with(['connection', 'location', 'items', 'payments'])
            ->get()->groupBy('pdv_connection_id');
        $checkpoints = PdvSyncCheckpoint::query()->whereIn('pdv_connection_id', $ids)
            ->where('stream', 'sales')->latest('last_attempt_at')->get()->groupBy('pdv_connection_id');
        $syncEvents = PdvIntegrationEvent::query()->whereIn('pdv_connection_id', $ids)
            ->whereIn('event_type', ['sync_completed', 'sync_failed'])->latest('created_at')->get()->groupBy('pdv_connection_id');

        return $connections->mapWithKeys(function (PdvConnection $connection) use ($orders, $checkpoints, $syncEvents): array {
            $connectionOrders = $orders->get($connection->id, collect());
            $pending = $connectionOrders->where('processing_state', PdvOrder::STATE_STAGED);
            $ready = $pending->filter(fn (PdvOrder $order): bool => $this->reconciliation->reconcile($order)['ready_for_import'])->count();

            return [$connection->id => [
                'last_sync' => $syncEvents->get($connection->id)?->first()?->created_at,
                'checkpoint' => $checkpoints->get($connection->id)?->first(),
                'staged' => $pending->count(),
                'ready' => $ready,
                'blocked' => $pending->count() - $ready,
                'imported' => $connectionOrders->where('processing_state', PdvOrder::STATE_IMPORTED)->count(),
                'reversed' => $connectionOrders->where('processing_state', PdvOrder::STATE_REVERSED)->count(),
                'operational_start_at' => $connection->operational_start_at,
                'sync_enabled' => (bool) config('pdv.enabled') && (bool) config('pdv.sync_enabled'),
                'import_enabled' => (bool) config('pdv.import_enabled'),
            ]];
        });
    }
}
