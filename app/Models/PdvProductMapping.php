<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdvProductMapping extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['confidence' => 'decimal:4'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PdvConnection::class, 'pdv_connection_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
