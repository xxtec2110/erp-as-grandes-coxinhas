<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppInboundMessage extends Model
{
    protected $table = 'whatsapp_inbound_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['original_timestamp' => 'datetime', 'received_at' => 'datetime', 'processed_at' => 'datetime', 'last_failed_at' => 'datetime', 'next_retry_at' => 'datetime', 'metadata' => 'array'];
    }

    public function conversationMessage(): BelongsTo
    {
        return $this->belongsTo(AgentConversationMessage::class, 'agent_conversation_message_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AgentAttachment::class);
    }
}
