<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentCostSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['monthly_budget' => 'decimal:6', 'warning_threshold' => 'decimal:6', 'saving_threshold' => 'decimal:6', 'critical_threshold' => 'decimal:6', 'monthly_host_cost' => 'decimal:6', 'usd_brl_rate' => 'decimal:8', 'automatic_saving_mode' => 'boolean', 'meta_rates' => 'array', 'model_rates' => 'array', 'cost_alerted_at' => 'datetime'];
    }
}
