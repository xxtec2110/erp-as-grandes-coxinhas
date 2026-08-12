<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppOutboundMessage extends Model
{
    protected $table = 'whatsapp_outbound_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WhatsAppWebhookEvent::class, 'whatsapp_webhook_event_id');
    }
}
