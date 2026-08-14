<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdvIntegrationEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }
}
