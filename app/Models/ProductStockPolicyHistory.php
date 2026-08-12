<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductStockPolicyHistory extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'previous_minimum_quantity' => 'decimal:6',
            'new_minimum_quantity' => 'decimal:6',
            'previous_target_quantity' => 'decimal:6',
            'new_target_quantity' => 'decimal:6',
            'previous_active' => 'boolean',
            'new_active' => 'boolean',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ProductStockPolicy::class, 'product_stock_policy_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
