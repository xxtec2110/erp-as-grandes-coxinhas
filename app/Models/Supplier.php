<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'document_type',
        'document_number',
        'contact_name',
        'phone',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function ingredientPrices(): HasMany
    {
        return $this->hasMany(IngredientPrice::class);
    }
}
