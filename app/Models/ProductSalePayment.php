<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductSalePayment extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REVERSAL = 'reversal';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'external_amount_snapshot' => 'decimal:2',
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'fee_percentage_snapshot' => 'decimal:6',
            'fixed_fee_snapshot' => 'decimal:4',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductSaleOrder::class, 'product_sale_order_id');
    }

    public function pdvOrderPayment(): BelongsTo
    {
        return $this->belongsTo(PdvOrderPayment::class);
    }

    public function acquirer(): BelongsTo
    {
        return $this->belongsTo(Acquirer::class);
    }

    public function cardBrand(): BelongsTo
    {
        return $this->belongsTo(CardBrand::class);
    }

    public function paymentFee(): BelongsTo
    {
        return $this->belongsTo(PaymentFee::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ProductSalePaymentAllocation::class);
    }
}
