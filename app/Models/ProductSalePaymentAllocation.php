<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSalePaymentAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'gross_allocated' => 'decimal:2',
            'revenue_allocated' => 'decimal:2',
            'fee_allocated' => 'decimal:2',
            'net_allocated' => 'decimal:2',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(ProductSalePayment::class, 'product_sale_payment_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(ProductSale::class, 'product_sale_id');
    }
}
