<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentConversation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AgentConversationMessage::class);
    }

    public function pendingActions(): HasMany
    {
        return $this->hasMany(PendingAgentAction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AgentEvent::class);
    }
}
