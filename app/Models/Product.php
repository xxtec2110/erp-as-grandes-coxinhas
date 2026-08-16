<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use HasFactory;

    public const UNIT_GRAM = 'g';

    public const UNIT_MILLILITER = 'ml';

    public const UNIT_COUNT = 'un';

    protected $fillable = ['name', 'product_category_id', 'stock_unit', 'sort_order', 'active'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'active' => 'boolean'];
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function productionRecords(): HasMany
    {
        return $this->hasMany(ProductionRecord::class);
    }

    public function stockTransferItems(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function stockPolicies(): HasMany
    {
        return $this->hasMany(ProductStockPolicy::class);
    }

    public function losses(): HasMany
    {
        return $this->hasMany(ProductLoss::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(ProductSale::class);
    }

    public function recipe(): HasOne
    {
        return $this->hasOne(ProductRecipe::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ProductAlias::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function currentPrice(): HasOne
    {
        return $this->hasOne(ProductPrice::class)->where('is_current', true);
    }
}
