<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdvPaymentMethodMapping extends Model
{
    protected $guarded = ['id'];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PdvConnection::class, 'pdv_connection_id');
    }

    public function acquirer(): BelongsTo
    {
        return $this->belongsTo(Acquirer::class);
    }

    public function cardBrand(): BelongsTo
    {
        return $this->belongsTo(CardBrand::class);
    }
}
