<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProductRecipeService
{
    public function __construct(private ProductPriceService $prices) {}

    public function save(Product $product, array $data, ?User $user = null, string $source = 'web', ?string $idempotencyKey = null): ProductRecipe
    {
        return DB::transaction(function () use ($product, $data, $user, $source, $idempotencyKey) {
            $ingredients = $data['ingredients'] ?? [];
            $preparations = $data['preparations'] ?? [];
            $sellingPrice = $data['selling_price'] ?? null;
            unset($data['ingredients'], $data['preparations'], $data['selling_price']);
            $recipe = $product->recipe()->updateOrCreate([], $data);
            $recipe->ingredients()->delete();
            $recipe->preparations()->delete();
            foreach ($ingredients as $item) {
                $recipe->ingredients()->create($item);
            }
            foreach ($preparations as $item) {
                $recipe->preparations()->create($item);
            }
            if ($sellingPrice !== null) {
                $this->prices->record($product, (string) $sellingPrice, $user, $source, $idempotencyKey ? $idempotencyKey.':price' : null);
            }

            return $recipe->refresh();
        });
    }
}
