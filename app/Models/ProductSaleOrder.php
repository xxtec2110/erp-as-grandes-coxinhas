<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductSaleOrder extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REVERSED = 'reversed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'operation_date' => 'date',
            'subtotal_snapshot' => 'decimal:2',
            'discount_total_snapshot' => 'decimal:2',
            'service_total_snapshot' => 'decimal:2',
            'delivery_total_snapshot' => 'decimal:2',
            'total_amount_snapshot' => 'decimal:2',
            'paid_total_snapshot' => 'decimal:2',
            'change_total_snapshot' => 'decimal:2',
            'imported_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function pdvConnection(): BelongsTo
    {
        return $this->belongsTo(PdvConnection::class);
    }

    public function pdvOrder(): BelongsTo
    {
        return $this->belongsTo(PdvOrder::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(ProductSale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ProductSalePayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
