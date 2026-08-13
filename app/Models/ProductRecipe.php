<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductRecipe extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['final_weight_grams' => 'decimal:6', 'yield_quantity' => 'decimal:6', 'technical_loss_percentage' => 'decimal:6', 'packaging_cost' => 'decimal:6', 'selling_price' => 'decimal:4'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(ProductRecipeIngredient::class);
    }

    public function preparations(): HasMany
    {
        return $this->hasMany(ProductRecipePreparation::class);
    }
}
