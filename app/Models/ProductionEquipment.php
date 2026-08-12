<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionEquipment extends Model
{
    use HasFactory;

    protected $table = 'production_equipment';

    protected $fillable = [
        'name',
        'type',
        'description',
        'energy_source',
        'nominal_glp_consumption_kg_hour',
        'power',
        'power_unit',
        'default_utilization_factor',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'nominal_glp_consumption_kg_hour' => 'decimal:6',
            'power' => 'decimal:4',
            'default_utilization_factor' => 'decimal:3',
            'active' => 'boolean',
        ];
    }

    public function burners(): HasMany
    {
        return $this->hasMany(EquipmentBurner::class);
    }

    public function preparationEnergyUsages(): HasMany
    {
        return $this->hasMany(PreparationEnergyUsage::class);
    }

    public function energySourceLabel(): string
    {
        return match ($this->energy_source) {
            'glp' => 'GLP',
            'electric' => 'Energia elétrica',
            'other' => 'Outro',
            default => $this->energy_source,
        };
    }
}
