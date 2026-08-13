<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionOrderItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['planned_quantity' => 'decimal:6', 'produced_quantity' => 'decimal:6', 'recipe_snapshot' => 'array', 'unit_cost_snapshot' => 'decimal:8', 'total_cost_snapshot' => 'decimal:8'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
