<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PdvOrderPayment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'external_total' => 'decimal:2',
            'fees' => 'decimal:2',
            'paid_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'present_in_latest' => 'boolean',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PdvOrder::class, 'pdv_order_id');
    }

    public function officialPayment(): HasOne
    {
        return $this->hasOne(ProductSalePayment::class);
    }
}
