<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentFee extends Model
{
    public const METHOD_DEBIT = 'debit';

    public const METHOD_CREDIT = 'credit';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fee_percentage' => 'decimal:6', 'fixed_fee' => 'decimal:4', 'effective_from' => 'date', 'effective_until' => 'date', 'is_current' => 'boolean', 'active' => 'boolean'];
    }

    public function acquirer(): BelongsTo
    {
        return $this->belongsTo(Acquirer::class);
    }

    public function cardBrand(): BelongsTo
    {
        return $this->belongsTo(CardBrand::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(PaymentFeeImport::class, 'payment_fee_import_id');
    }

    public function methodLabel(): string
    {
        return $this->payment_method === self::METHOD_DEBIT ? 'Débito' : 'Crédito';
    }
}
