<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRecipePreparation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6'];
    }

    public function preparation(): BelongsTo
    {
        return $this->belongsTo(Preparation::class);
    }
}
