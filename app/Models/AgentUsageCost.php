<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentUsageCost extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['estimated_cost' => 'decimal:6', 'billable' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
