<?php

namespace App\Services;

use App\Models\CatalogAdminAudit;
use App\Models\PdvConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class PdvMappingAuditService
{
    public function __construct(private CatalogAdminAuditService $audits) {}

    public function execute(
        User $user,
        PdvConnection $connection,
        string $type,
        string $externalIdentifier,
        string $action,
        string $idempotencyKey,
        callable $operation,
        ?Model $before = null,
        ?string $reason = null,
    ): Model {
        return $this->audits->execute(
            $user,
            'web',
            "pdv.mapping.{$type}.{$action}",
            "pdv-mapping:{$connection->id}:{$idempotencyKey}",
            $operation,
            $before === null ? null : clone $before,
            [
                'pdv_connection_id' => $connection->id,
                'location_id' => $connection->location_id,
                'mapping_type' => $type,
                'external_identifier' => $externalIdentifier,
                'action' => $action,
                'reason' => $reason,
            ],
        );
    }

    /** @return Collection<int, CatalogAdminAudit> */
    public function history(PdvConnection $connection, int $limit = 30): Collection
    {
        return CatalogAdminAudit::query()
            ->with('user')
            ->where('tool_name', 'like', 'pdv.mapping.%')
            ->latest('confirmed_at')
            ->limit(200)
            ->get()
            ->filter(fn (CatalogAdminAudit $audit): bool => (int) ($audit->context['pdv_connection_id'] ?? 0) === $connection->id)
            ->take($limit)
            ->values();
    }
}
