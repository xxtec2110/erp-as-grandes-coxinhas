<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdvSyncCheckpoint extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cursor' => 'array', 'last_attempt_at' => 'datetime', 'last_success_at' => 'datetime'];
    }
}
