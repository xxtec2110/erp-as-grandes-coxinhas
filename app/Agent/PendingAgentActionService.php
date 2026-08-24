<?php

namespace App\Agent;

use App\Models\PendingAgentAction;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class PendingAgentActionService
{
    public function prepare(User $user, string $tool, array $payload, array $missing, string $key, ?int $conversationId = null): PendingAgentAction
    {
        return PendingAgentAction::query()->firstOrCreate(['idempotency_key' => $key], ['user_id' => $user->id, 'agent_conversation_id' => $conversationId, 'tool_name' => $tool, 'payload' => $payload, 'missing_fields' => $missing, 'status' => 'pending', 'expires_at' => now()->addDay()]);
    }

    public function merge(PendingAgentAction $action, User $user, array $payload, array $missing): PendingAgentAction
    {
        $this->authorizeActor($action, $user);
        $this->ensureNotExpired($action);
        if ($action->status !== 'pending') {
            throw new DomainException('A ação não está mais pendente.');
        }
        $action->update(['payload' => [...$action->payload, ...$payload], 'missing_fields' => $missing]);

        return $action->refresh();
    }

    public function cancel(PendingAgentAction $action, User $user): PendingAgentAction
    {
        $this->authorizeActor($action, $user);
        $this->ensureNotExpired($action);
        if ($action->status === 'executed') {
            return $action;
        }
        $action->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return $action->refresh();
    }

    public function confirm(PendingAgentAction $action, User $user, AgentToolExecutor $executor): PendingAgentAction
    {
        $this->authorizeActor($action, $user);
        $this->ensureNotExpired($action);

        return DB::transaction(function () use ($action, $user, $executor): PendingAgentAction {
            $locked = PendingAgentAction::query()->lockForUpdate()->findOrFail($action->id);
            $this->authorizeActor($locked, $user);
            $this->ensureNotExpired($locked);
            if ($locked->status === 'executed') {
                return $locked;
            }
            if ($locked->status !== 'pending' || ! empty($locked->missing_fields)) {
                throw new DomainException('A ação ainda não está pronta para confirmação.');
            }
            $locked->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            $result = $executor->execute($locked->tool_name, $locked->payload, $user, true, ['channel' => $locked->conversation?->channel ?? 'agent', 'conversation_id' => $locked->agent_conversation_id, 'pending_action_id' => $locked->id, 'tool' => $locked->tool_name]);
            $locked->update(['status' => 'executed', 'executed_at' => now(), 'result' => ['type' => $result::class, 'id' => $result->getKey()]]);

            return $locked->refresh();
        });
    }

    public function expireStaleForConversation(int $conversationId): int
    {
        return PendingAgentAction::query()
            ->where('agent_conversation_id', $conversationId)
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'failure_reason' => 'action_expired', 'updated_at' => now()]);
    }

    private function authorizeActor(PendingAgentAction $action, User $user): void
    {
        if ($action->user_id !== $user->id) {
            throw new AuthorizationException('A ação pertence a outro usuário.');
        }
    }

    private function ensureNotExpired(PendingAgentAction $action): void
    {
        if (! $action->expires_at?->isPast()) {
            return;
        }

        if ($action->status === 'pending') {
            $action->update(['status' => 'expired', 'failure_reason' => 'action_expired']);
        }

        throw new DomainException('Esta ação expirou e não foi executada. Envie o comando novamente.');
    }
}
