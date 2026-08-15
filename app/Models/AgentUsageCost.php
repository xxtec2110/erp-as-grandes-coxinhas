<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentUsageCost extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'estimated_cost' => 'decimal:12',
            'cost_usd' => 'decimal:12',
            'fx_rate' => 'decimal:8',
            'cost_brl' => 'decimal:12',
            'pricing_date' => 'date',
            'billable' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
