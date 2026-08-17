<?php

namespace App\Agent;

use App\Models\PendingAgentAction;
use App\Models\User;

class PendingAgentActionService
{
    public function prepare(User $user, string $tool, array $payload, array $missing, string $key, ?int $conversationId = null): PendingAgentAction
    {
        return PendingAgentAction::query()->firstOrCreate(['idempotency_key' => $key], ['user_id' => $user->id, 'agent_conversation_id' => $conversationId, 'tool_name' => $tool, 'payload' => $payload, 'missing_fields' => $missing, 'status' => 'pending', 'expires_at' => now()->addDay()]);
    }

    public function merge(PendingAgentAction $action, User $user, array $payload, array $missing): PendingAgentAction
    {
        abort_unless($action->user_id === $user->id, 403);
        abort_unless($action->status === 'pending', 409);
        abort_if($action->expires_at?->isPast(), 409);
        $action->update(['payload' => [...$action->payload, ...$payload], 'missing_fields' => $missing]);

        return $action->refresh();
    }

    public function cancel(PendingAgentAction $action, User $user): PendingAgentAction
    {
        abort_unless($action->user_id === $user->id, 403);
        abort_if($action->expires_at?->isPast(), 409);
        if ($action->status === 'executed') {
            return $action;
        }
        $action->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return $action->refresh();
    }

    public function confirm(PendingAgentAction $action, User $user, AgentToolExecutor $executor): PendingAgentAction
    {
        abort_unless($action->user_id === $user->id, 403);
        abort_if($action->expires_at?->isPast(), 409);
        if ($action->status === 'executed') {
            return $action;
        }
        abort_unless($action->status === 'pending' && empty($action->missing_fields), 409);
        $action->update(['status' => 'confirmed', 'confirmed_at' => now()]);
        $result = $executor->execute($action->tool_name, $action->payload, $user, true, ['channel' => $action->conversation?->channel ?? 'agent']);
        $action->update(['status' => 'executed', 'executed_at' => now(), 'result' => ['type' => $result::class, 'id' => $result->getKey()]]);

        return $action->refresh();
    }
}
