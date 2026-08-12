<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentFeeImport extends Model
{
    public const RECEIVED = 'received';

    public const PARSED = 'parsed';

    public const AWAITING_CONFIRMATION = 'awaiting_confirmation';

    public const CONFIRMED = 'confirmed';

    public const APPLIED = 'applied';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['parsed_payload' => 'array', 'validation_errors' => 'array', 'confirmed_at' => 'datetime'];
    }

    public function acquirer(): BelongsTo
    {
        return $this->belongsTo(Acquirer::class);
    }
}
