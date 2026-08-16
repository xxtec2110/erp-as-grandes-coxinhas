<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class ProductPriceService
{
    public function record(Product $product, string $price, ?User $user = null, string $source = 'web', ?string $idempotencyKey = null, ?string $effectiveDate = null): ProductPrice
    {
        return DB::transaction(function () use ($product, $price, $user, $source, $idempotencyKey, $effectiveDate): ProductPrice {
            if ($idempotencyKey !== null && ($existing = ProductPrice::query()->where('idempotency_key', $idempotencyKey)->first())) {
                return $existing;
            }

            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $current = $product->prices()->where('is_current', true)->first();
            if ($current !== null && BigDecimal::of($current->price)->isEqualTo(BigDecimal::of($price))) {
                return $current;
            }

            $product->prices()->where('is_current', true)->update(['is_current' => false]);

            return $product->prices()->create([
                'price' => $price,
                'effective_date' => $effectiveDate ?? now()->toDateString(),
                'is_current' => true,
                'source' => $source,
                'created_by' => $user?->id,
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }
}
