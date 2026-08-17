<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseDocumentItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6', 'received_quantity' => 'decimal:6', 'unit_price' => 'decimal:4', 'total_price' => 'decimal:2',
            'package_quantity' => 'decimal:6', 'package_size' => 'decimal:6', 'unit_price_original' => 'decimal:6',
            'package_price' => 'decimal:4', 'gross_amount' => 'decimal:2', 'discount_amount' => 'decimal:2',
            'freight_amount' => 'decimal:2', 'other_charges_amount' => 'decimal:2', 'net_amount' => 'decimal:2',
            'normalized_quantity' => 'decimal:6', 'normalized_unit_cost' => 'decimal:8',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PurchaseDocument::class, 'purchase_document_id');
    }

    public function priceHistory(): HasOne
    {
        return $this->hasOne(IngredientPrice::class, 'purchase_item_id');
    }
}
