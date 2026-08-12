<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreparationIngredient extends Model
{
    use HasFactory;

    protected $fillable = ['ingredient_id', 'quantity', 'unit'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6'];
    }

    public function preparation(): BelongsTo
    {
        return $this->belongsTo(Preparation::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
