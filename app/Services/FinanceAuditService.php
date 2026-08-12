<?php

namespace App\Services;

use App\Models\FinanceAudit;
use App\Models\User;

class FinanceAuditService
{
    public function record(string $action, object $model, ?User $user, array $new, ?array $old = null, string $channel = 'web', ?string $key = null): void
    {
        FinanceAudit::query()->create(['user_id' => $user?->id, 'channel' => $channel, 'action' => $action, 'auditable_type' => $model::class, 'auditable_id' => $model->getKey(), 'previous_value' => $old, 'new_value' => $new, 'idempotency_key' => $key]);
    }
}
