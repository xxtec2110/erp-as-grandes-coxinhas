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
    ];

    protected function casts(): array
    {
        return [
            'purchase_quantity' => 'decimal:4',
            'normalized_quantity' => 'decimal:6',
            'price_paid' => 'decimal:2',
            'base_unit_cost' => 'decimal:8',
            'effective_date' => 'date',
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
}
