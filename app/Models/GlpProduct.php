<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GlpProduct extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'net_weight_kg', 'notes', 'active'];

    protected function casts(): array
    {
        return [
            'net_weight_kg' => 'decimal:4',
            'active' => 'boolean',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(GlpPrice::class);
    }

    public function currentPrice(): HasOne
    {
        return $this->hasOne(GlpPrice::class)->where('is_current', true);
    }

    public function preparationEnergyUsages(): HasMany
    {
        return $this->hasMany(PreparationEnergyUsage::class);
    }
}
