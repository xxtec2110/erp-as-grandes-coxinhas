<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSale extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'unit_price' => 'decimal:4', 'total_amount' => 'decimal:2', 'gross_amount' => 'decimal:2', 'fee_percentage_snapshot' => 'decimal:6', 'fixed_fee_snapshot' => 'decimal:4', 'fee_amount_snapshot' => 'decimal:2', 'net_amount' => 'decimal:2', 'unit_cost_snapshot' => 'decimal:8', 'total_cost_snapshot' => 'decimal:2', 'gross_profit_snapshot' => 'decimal:2', 'gross_margin_percentage_snapshot' => 'decimal:4', 'operation_date' => 'date', 'external_updated_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    public function pdvConnection(): BelongsTo
    {
        return $this->belongsTo(PdvConnection::class);
    }

    public function costSnapshot(): BelongsTo
    {
        return $this->belongsTo(ProductCostSnapshot::class, 'product_cost_snapshot_id');
    }
}
