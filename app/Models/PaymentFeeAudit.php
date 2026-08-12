<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentFeeAudit extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['previous_value' => 'array', 'new_value' => 'array', 'created_at' => 'datetime'];
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(PaymentFee::class, 'payment_fee_id');
    }
}
