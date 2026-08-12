<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ingredient extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'ingredient_category_id', 'brand', 'base_unit', 'notes', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(IngredientPrice::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(IngredientCategory::class, 'ingredient_category_id');
    }

    public function currentPrice(): HasOne
    {
        return $this->hasOne(IngredientPrice::class)->where('is_current', true);
    }

    public function preparationIngredients(): HasMany
    {
        return $this->hasMany(PreparationIngredient::class);
    }
}
