<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostCenter extends Model
{
    protected $fillable = ['name', 'location_id', 'active', 'notes'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
