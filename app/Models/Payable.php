<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payable extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expected_amount' => 'decimal:2', 'competency_date' => 'date', 'due_date' => 'date', 'recurring' => 'boolean'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function paidAmount(): string
    {
        return (string) $this->payments()->where('status', 'completed')->sum('amount');
    }
}
