<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdvOrder extends Model
{
    public const STATE_STAGED = 'staged';

    public const STATE_IMPORTED = 'imported';

    public const STATE_REVERSED = 'reversed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'service_total' => 'decimal:2',
            'delivery_total' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'change_total' => 'decimal:2',
            'external_created_at' => 'immutable_datetime',
            'external_completed_at' => 'immutable_datetime',
            'external_updated_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'source_changed_at' => 'immutable_datetime',
            'imported_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PdvConnection::class, 'pdv_connection_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PdvOrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PdvOrderPayment::class);
    }
}
