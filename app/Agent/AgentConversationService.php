<?php

namespace App\Agent;

use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\User;

class AgentConversationService
{
    public function conversation(User $user, string $channel = 'internal', ?string $externalId = null): AgentConversation
    {
        return AgentConversation::query()->firstOrCreate(['user_id' => $user->id, 'channel' => $channel, 'external_conversation_id' => $externalId, 'status' => 'active'], ['context' => []]);
    }

    public function message(AgentConversation $conversation, string $role, string $content, ?array $payload = null, ?string $externalId = null): AgentConversationMessage
    {
        if ($externalId !== null && $conversation->messages()->where('external_message_id', $externalId)->exists()) {
            return $conversation->messages()->where('external_message_id', $externalId)->firstOrFail();
        }

        return $conversation->messages()->create(['role' => $role, 'content' => $content, 'structured_payload' => $payload, 'external_message_id' => $externalId]);
    }
}
