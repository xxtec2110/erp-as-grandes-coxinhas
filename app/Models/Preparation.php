<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Preparation extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'initial_quantity',
        'initial_unit',
        'expected_yield',
        'yield_unit',
        'actual_final_quantity',
        'total_preparation_time_minutes',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'initial_quantity' => 'decimal:6',
            'expected_yield' => 'decimal:6',
            'actual_final_quantity' => 'decimal:6',
            'total_preparation_time_minutes' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function preparationIngredients(): HasMany
    {
        return $this->hasMany(PreparationIngredient::class);
    }

    public function energyUsages(): HasMany
    {
        return $this->hasMany(PreparationEnergyUsage::class);
    }

    public function additionalCosts(): HasMany
    {
        return $this->hasMany(PreparationAdditionalCost::class);
    }
}
