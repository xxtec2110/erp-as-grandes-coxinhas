<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreparationAdditionalCost extends Model
{
    use HasFactory;

    protected $fillable = ['description', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function preparation(): BelongsTo
    {
        return $this->belongsTo(Preparation::class);
    }
}
