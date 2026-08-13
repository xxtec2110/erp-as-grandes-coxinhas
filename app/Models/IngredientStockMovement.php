<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class IngredientStockMovement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity_delta' => 'decimal:6', 'unit_cost_snapshot' => 'decimal:8', 'operation_date' => 'date', 'metadata' => 'array'];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
