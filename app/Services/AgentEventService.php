<?php

namespace App\Services;

use App\Models\AgentEvent;
use App\Models\User;

class AgentEventService
{
    public function record(string $type, string $channel, ?User $user = null, ?int $conversationId = null, ?string $messageId = null, ?string $tool = null, array $metadata = [], ?int $identityId = null, ?string $status = null, ?string $errorCode = null, ?int $durationMs = null): void
    {
        AgentEvent::query()->create(['event_type' => $type, 'channel' => $channel, 'user_id' => $user?->id, 'agent_conversation_id' => $conversationId, 'external_message_id' => $messageId, 'tool_name' => $tool, 'metadata' => $metadata, 'user_external_identity_id' => $identityId, 'status' => $status, 'error_code' => $errorCode, 'duration_ms' => $durationMs]);
    }
}
