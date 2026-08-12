<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquipmentBurner extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'nominal_glp_consumption_kg_hour',
        'power',
        'power_unit',
        'default_utilization_factor',
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

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(ProductionEquipment::class, 'production_equipment_id');
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'simple' => 'Simples',
            'double' => 'Duplo',
            'custom' => 'Personalizado',
            default => $this->type,
        };
    }

    public function preparationEnergyUsages(): HasMany
    {
        return $this->hasMany(PreparationEnergyUsage::class);
    }
}
