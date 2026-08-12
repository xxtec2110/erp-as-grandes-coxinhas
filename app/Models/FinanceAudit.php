<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceAudit extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['previous_value' => 'array', 'new_value' => 'array', 'created_at' => 'datetime'];
    }
}
