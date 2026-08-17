<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'ingredient_id',
        'supplier_id',
        'purchase_quantity',
        'purchase_unit',
        'normalized_quantity',
        'price_paid',
        'base_unit_cost',
        'effective_date',
        'is_current',
        'location_id',
        'purchase_document_id',
        'purchase_item_id',
        'effective_at',
        'purchase_date',
        'received_at',
        'source_type',
        'package_quantity',
        'package_size',
        'package_unit',
        'unit_price_original',
        'package_price',
        'gross_total',
        'discount_amount',
        'freight_amount',
        'other_charges_amount',
        'net_total',
        'normalized_unit',
        'currency',
        'created_by',
        'source_channel',
    ];

    protected function casts(): array
    {
        return [
            'purchase_quantity' => 'decimal:4',
            'normalized_quantity' => 'decimal:6',
            'price_paid' => 'decimal:2',
            'base_unit_cost' => 'decimal:8',
            'effective_date' => 'date',
            'effective_at' => 'datetime',
            'purchase_date' => 'date',
            'received_at' => 'datetime',
            'package_quantity' => 'decimal:6',
            'package_size' => 'decimal:6',
            'unit_price_original' => 'decimal:6',
            'package_price' => 'decimal:4',
            'gross_total' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'freight_amount' => 'decimal:2',
            'other_charges_amount' => 'decimal:2',
            'net_total' => 'decimal:2',
            'is_current' => 'boolean',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function purchaseDocument(): BelongsTo
    {
        return $this->belongsTo(PurchaseDocument::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseDocumentItem::class, 'purchase_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
