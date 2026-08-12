<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppWebhookEvent extends Model
{
    protected $table = 'whatsapp_webhook_events';

    protected $guarded = ['id'];

    public function outboundMessages(): HasMany
    {
        return $this->hasMany(WhatsAppOutboundMessage::class, 'whatsapp_webhook_event_id');
    }
}
