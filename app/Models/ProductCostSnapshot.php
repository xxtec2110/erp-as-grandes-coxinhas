<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCostSnapshot extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime', 'unit_cost' => 'decimal:8', 'selling_price' => 'decimal:4',
            'gross_profit' => 'decimal:4', 'gross_margin_percentage' => 'decimal:4',
            'components' => 'array', 'context' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(ProductRecipe::class, 'product_recipe_id');
    }
}
