<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingAgentAction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'missing_fields' => 'array', 'result' => 'array', 'confirmed_at' => 'datetime', 'executed_at' => 'datetime', 'cancelled_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'agent_conversation_id');
    }
}
