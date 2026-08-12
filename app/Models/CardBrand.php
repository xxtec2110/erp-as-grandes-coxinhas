<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CardBrand extends Model
{
    protected $fillable = ['name', 'code', 'active', 'notes'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function fees(): HasMany
    {
        return $this->hasMany(PaymentFee::class);
    }
}
