<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceCategory extends Model
{
    protected $fillable = ['name', 'active', 'notes'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
