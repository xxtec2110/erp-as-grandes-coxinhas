<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlpPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'quantity_kg',
        'total_price',
        'unit_cost_per_kg',
        'effective_date',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'quantity_kg' => 'decimal:4',
            'total_price' => 'decimal:2',
            'unit_cost_per_kg' => 'decimal:8',
            'effective_date' => 'date',
            'is_current' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(GlpProduct::class, 'glp_product_id');
    }
}
