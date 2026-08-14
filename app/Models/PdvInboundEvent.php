<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdvInboundEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime', 'external_updated_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PdvConnection::class, 'pdv_connection_id');
    }
}
