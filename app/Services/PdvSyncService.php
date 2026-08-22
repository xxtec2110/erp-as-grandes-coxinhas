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
        [$providerCursor, $effectiveFrom, $effectiveTo, $operationalWindow] = $this->window($connection, $checkpoint, $from, $to);
        $started = hrtime(true);
        $this->events->record('sync_started', $connection, user: $user, status: 'running');
        try {
            $page = $this->providers->for($connection)->fetchSales($connection, $providerCursor, $effectiveFrom, $effectiveTo);
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
            if ($connection->provider !== 'grandchef' || $operationalWindow) {
                $checkpoint->update([
                    'cursor' => $operationalWindow && $page->nextCursor !== null ? [
                        'operational_window_version' => 1,
                        'provider_cursor' => $page->nextCursor,
                        'from' => $effectiveFrom?->toIso8601String(),
                        'to' => $effectiveTo?->toIso8601String(),
                    ] : $page->nextCursor,
                    'last_success_at' => $page->nextCursor === null ? ($effectiveTo ?? now()) : $checkpoint->last_success_at,
                    'last_error' => null,
                ]);
            } else {
                $checkpoint->update(['last_error' => null]);
            }
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

    /** @return array{0:?array,1:?CarbonImmutable,2:?CarbonImmutable,3:bool} */
    private function window(PdvConnection $connection, PdvSyncCheckpoint $checkpoint, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        if (($from === null) !== ($to === null)) {
            throw new IntegrationNotConfiguredException('A sincronização exige início e fim juntos.');
        }

        if ($connection->provider !== 'grandchef') {
            return [$checkpoint->cursor, $from, $to, false];
        }

        if ($from !== null && $to !== null) {
            return [null, $from, $to, false];
        }

        if ($connection->operational_start_at === null) {
            throw new IntegrationNotConfiguredException('Defina o marco oficial de início antes da sincronização operacional do GrandChef.');
        }

        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $operationalStart = CarbonImmutable::instance($connection->operational_start_at)->setTimezone($timezone);
        $cursor = $checkpoint->cursor;
        if (($cursor['operational_window_version'] ?? null) === 1) {
            $windowFrom = CarbonImmutable::parse((string) $cursor['from'], $timezone);
            if ($windowFrom->greaterThanOrEqualTo($operationalStart)) {
                return [
                    $cursor['provider_cursor'] ?? null,
                    $windowFrom,
                    CarbonImmutable::parse((string) $cursor['to'], $timezone),
                    true,
                ];
            }
        }

        $lastSuccess = $checkpoint->last_success_at === null
            ? null
            : CarbonImmutable::instance($checkpoint->last_success_at)->setTimezone($timezone);
        $hasOperationalCheckpoint = $lastSuccess !== null && $lastSuccess->greaterThanOrEqualTo($operationalStart);
        $effectiveFrom = $hasOperationalCheckpoint && $lastSuccess->greaterThan($operationalStart)
            ? $lastSuccess
            : $operationalStart;

        return [$hasOperationalCheckpoint ? $cursor : null, $effectiveFrom, CarbonImmutable::now($timezone), true];
    }
}
