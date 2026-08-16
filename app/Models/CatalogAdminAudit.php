<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogAdminAudit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array', 'before_values' => 'array', 'after_values' => 'array', 'confirmed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
