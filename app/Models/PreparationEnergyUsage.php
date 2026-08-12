<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreparationEnergyUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'production_equipment_id',
        'equipment_burner_id',
        'glp_product_id',
        'usage_time_minutes',
        'utilization_factor',
    ];

    protected function casts(): array
    {
        return [
            'usage_time_minutes' => 'decimal:2',
            'utilization_factor' => 'decimal:3',
        ];
    }

    public function preparation(): BelongsTo
    {
        return $this->belongsTo(Preparation::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(ProductionEquipment::class, 'production_equipment_id');
    }

    public function burner(): BelongsTo
    {
        return $this->belongsTo(EquipmentBurner::class, 'equipment_burner_id');
    }

    public function glpProduct(): BelongsTo
    {
        return $this->belongsTo(GlpProduct::class);
    }
}
