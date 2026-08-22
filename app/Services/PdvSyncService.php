<?php

namespace App\Services;

use App\Models\PdvConnection;
use App\Models\PdvSyncCheckpoint;
use App\Models\User;
use App\Pdv\IntegrationNotConfiguredException;
use App\Pdv\PdvProviderManager;
use Carbon\CarbonImmutable;
use Throwable;

class PdvSyncService
{
    public function __construct(private PdvProviderManager $providers, private PdvInboundService $inbound, private PdvSaleImportService $imports, private PdvOrderStagingService $staging, private PdvIntegrationEventService $events, private PdvConnectionAccessService $access) {}

    public function sync(PdvConnection $connection, User $user, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        if (! config('pdv.enabled') || ! config('pdv.sync_enabled') || ! $connection->enabled) {
            throw new IntegrationNotConfiguredException('Sincronização de PDV desativada.');
        }
        $location = $this->access->assertOperationalScope($connection);
        $this->access->authorizeConnection($user, $connection);
        $checkpoint = PdvSyncCheckpoint::query()->firstOrCreate(['pdv_connection_id' => $connection->id, 'location_id' => $location->id, 'stream' => 'sales']);
        $checkpoint->update(['last_attempt_at' => now()]);
        $started = hrtime(true);
        $this->events->record('sync_started', $connection, user: $user, status: 'running');
        try {
            $page = $this->providers->for($connection)->fetchSales($connection, $checkpoint->cursor, $from, $to);
            $result = ['staged' => 0, 'imported' => 0, 'waiting_mapping' => 0];
            foreach ($page->items as $sale) {
                $eventId = $this->inbound->syntheticEventId($connection->provider, $sale->externalSaleId, 'sale.updated', $sale->updatedAt->toIso8601String());
                $event = $this->inbound->receive($connection, $eventId, 'sale.updated', ['normalized' => true], $sale->externalSaleId);
                if ($connection->provider === 'grandchef') {
                    $this->staging->stage($connection, $sale);
                    $event->update(['status' => 'received', 'error_code' => null, 'error_message' => null]);
                    $result['staged']++;
                } else {
                    $outcome = $this->imports->import($connection, $sale, $user, $event);
                    $result[$outcome['status']] = ($result[$outcome['status']] ?? 0) + 1;
                }
            }
            $checkpoint->update(['cursor' => $page->nextCursor, 'last_success_at' => now(), 'last_error' => null]);
            $connection->update(['last_success_at' => now(), 'status' => 'healthy']);
            $this->events->record('sync_completed', $connection, user: $user, status: 'success', metadata: $result, durationMs: (int) ((hrtime(true) - $started) / 1_000_000));

            return $result;
        } catch (Throwable $e) {
            $checkpoint->update(['last_error' => class_basename($e)]);
            $connection->update(['last_failure_at' => now(), 'status' => 'degraded']);
            $connection->increment('events_failed_count');
            $this->events->record('sync_failed', $connection, user: $user, status: 'failed', metadata: ['error' => class_basename($e)], durationMs: (int) ((hrtime(true) - $started) / 1_000_000));
            throw $e;
        }
    }
}
