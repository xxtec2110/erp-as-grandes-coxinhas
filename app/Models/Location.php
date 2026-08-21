<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    public const TYPE_PRODUCTION = 'production';

    public const TYPE_STORE = 'store';

    protected $fillable = ['name', 'type', 'daily_sales_target', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'daily_sales_target' => 'decimal:6'];
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function productionRecords(): HasMany
    {
        return $this->hasMany(ProductionRecord::class);
    }

    public function outgoingStockTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'source_location_id');
    }

    public function incomingStockTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'destination_location_id');
    }

    public function productStockPolicies(): HasMany
    {
        return $this->hasMany(ProductStockPolicy::class);
    }

    public function productLosses(): HasMany
    {
        return $this->hasMany(ProductLoss::class);
    }

    public function pdvConnections(): HasMany
    {
        return $this->hasMany(PdvConnection::class);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_PRODUCTION => 'Produção',
            self::TYPE_STORE => 'Loja',
            default => $this->type,
        };
    }
}
