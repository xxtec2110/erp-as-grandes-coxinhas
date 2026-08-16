<?php

namespace App\Services;

use App\Models\CatalogAdminAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

class CatalogAdminAuditService
{
    public function execute(User $user, string $channel, string $tool, string $key, callable $operation, ?Model $before = null, array $context = []): Model
    {
        if ($existing = CatalogAdminAudit::query()->where('idempotency_key', $key)->where('status', 'success')->first()) {
            /** @var class-string<Model> $type */
            $type = $existing->entity_type;

            return $type::query()->findOrFail($existing->entity_id);
        }

        try {
            return DB::transaction(function () use ($user, $channel, $tool, $key, $operation, $before, $context): Model {
                $result = $operation();
                CatalogAdminAudit::query()->updateOrCreate(['idempotency_key' => $key], [
                    'user_id' => $user->id,
                    'location_id' => $context['location_id'] ?? null,
                    'channel' => $channel,
                    'context' => $context,
                    'tool_name' => $tool,
                    'entity_type' => $result::class,
                    'entity_id' => $result->getKey(),
                    'before_values' => $before?->getAttributes(),
                    'after_values' => $result->fresh()?->getAttributes(),
                    'confirmed_at' => now(),
                    'status' => 'success',
                    'error_message' => null,
                ]);

                return $result;
            });
        } catch (Throwable $exception) {
            CatalogAdminAudit::query()->firstOrCreate(['idempotency_key' => $key], [
                'user_id' => $user->id,
                'location_id' => $context['location_id'] ?? null,
                'channel' => $channel,
                'context' => $context,
                'tool_name' => $tool,
                'entity_type' => $before ? get_class($before) : Model::class,
                'entity_id' => $before?->getKey(),
                'before_values' => $before?->getAttributes(),
                'confirmed_at' => now(),
                'status' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            throw $exception;
        }
    }
}
