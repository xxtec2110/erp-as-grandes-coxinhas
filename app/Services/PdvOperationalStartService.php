<?php

namespace App\Services;

use App\Models\CatalogAdminAudit;
use App\Models\PdvConnection;
use App\Models\ProductSaleOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class PdvOperationalStartService
{
    public function __construct(private AuthorizationService $authorization) {}

    public function set(PdvConnection $connection, CarbonImmutable $value, User $user, string $idempotencyKey): PdvConnection
    {
        $this->authorization->authorize($user, 'pdv.manage', (int) $connection->location_id);
        $auditKey = "pdv-operational-start:{$connection->id}:{$idempotencyKey}";
        $normalized = $value->setTimezone(config('app.timezone', 'America/Sao_Paulo'));

        if ($existing = CatalogAdminAudit::query()->where('idempotency_key', $auditKey)->where('status', 'success')->first()) {
            if (($existing->after_values['operational_start_at'] ?? null) !== $normalized->toIso8601String()) {
                throw new DomainException('A chave idempotente já foi usada para outro marco operacional.');
            }

            return $connection->fresh();
        }

        return DB::transaction(function () use ($connection, $normalized, $user, $auditKey): PdvConnection {
            $locked = PdvConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $before = $locked->operational_start_at;

            $this->assertChangePreservesImportedHistory($locked, $normalized);

            $locked->update(['operational_start_at' => $normalized]);
            CatalogAdminAudit::query()->create([
                'user_id' => $user->id,
                'location_id' => $locked->location_id,
                'channel' => 'web',
                'context' => [
                    'pdv_connection_id' => $locked->id,
                    'location_id' => $locked->location_id,
                    'action' => $before === null ? 'set' : 'update',
                    'timezone' => config('app.timezone', 'America/Sao_Paulo'),
                ],
                'tool_name' => 'pdv.operational_start.update',
                'entity_type' => PdvConnection::class,
                'entity_id' => $locked->id,
                'before_values' => ['operational_start_at' => $before?->toIso8601String()],
                'after_values' => ['operational_start_at' => $normalized->toIso8601String()],
                'confirmed_at' => now(),
                'status' => 'success',
                'idempotency_key' => $auditKey,
            ]);

            return $locked->fresh(['location']);
        });
    }

    private function assertChangePreservesImportedHistory(PdvConnection $connection, CarbonImmutable $newValue): void
    {
        $orders = ProductSaleOrder::query()
            ->whereBelongsTo($connection, 'pdvConnection')
            ->whereNotNull('pdv_order_id')
            ->with('pdvOrder:id,external_completed_at')
            ->get();

        if ($orders->isEmpty()) {
            return;
        }

        if ($orders->contains(fn (ProductSaleOrder $order): bool => $order->pdvOrder?->external_completed_at === null)) {
            throw new DomainException('O marco não pode ser alterado porque existe venda oficial sem data externa comparável.');
        }

        if ($orders->contains(fn (ProductSaleOrder $order): bool => $order->pdvOrder->external_completed_at->lessThan($newValue))) {
            throw new DomainException('O novo marco invalidaria uma venda oficial já importada e não pode ser aplicado por este fluxo.');
        }
    }
}
