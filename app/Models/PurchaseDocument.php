<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseDocument extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date', 'due_date' => 'date', 'received_date' => 'date', 'received_at' => 'datetime',
            'total_amount' => 'decimal:2', 'gross_amount' => 'decimal:2', 'discount_amount' => 'decimal:2',
            'freight_amount' => 'decimal:2', 'other_charges_amount' => 'decimal:2', 'net_amount' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseDocumentItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(IngredientPrice::class);
    }
}
