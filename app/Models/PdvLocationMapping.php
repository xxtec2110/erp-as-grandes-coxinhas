<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdvLocationMapping extends Model
{
    protected $guarded = ['id'];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PdvConnection::class, 'pdv_connection_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
