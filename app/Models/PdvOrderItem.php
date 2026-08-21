<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PdvOrderItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_price' => 'decimal:4',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'cancelled' => 'boolean',
            'present_in_latest' => 'boolean',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PdvOrder::class, 'pdv_order_id');
    }

    public function productSale(): HasOne
    {
        return $this->hasOne(ProductSale::class);
    }
}
