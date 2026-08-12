<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialAccount extends Model
{
    protected $fillable = ['name', 'institution', 'type', 'owner_name', 'location_id', 'active', 'notes'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
