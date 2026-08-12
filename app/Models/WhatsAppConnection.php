<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppConnection extends Model
{
    protected $table = 'whatsapp_connections';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_checked_at' => 'datetime', 'last_connected_at' => 'datetime',
            'last_disconnected_at' => 'datetime', 'last_received_at' => 'datetime',
            'last_sent_at' => 'datetime', 'disconnect_alerted_at' => 'datetime',
            'reconnect_alerted_at' => 'datetime',
        ];
    }
}
