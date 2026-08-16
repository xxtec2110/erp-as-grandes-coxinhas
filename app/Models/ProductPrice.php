<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPrice extends Model
{
    protected $fillable = ['product_id', 'price', 'effective_date', 'is_current', 'source', 'created_by', 'idempotency_key'];

    protected function casts(): array
    {
        return ['price' => 'decimal:4', 'effective_date' => 'date', 'is_current' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
