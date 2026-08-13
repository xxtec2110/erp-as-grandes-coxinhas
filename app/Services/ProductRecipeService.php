<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductRecipe;
use Illuminate\Support\Facades\DB;

class ProductRecipeService
{
    public function save(Product $product, array $data): ProductRecipe
    {
        return DB::transaction(function () use ($product, $data) {
            $ingredients = $data['ingredients'] ?? [];
            $preparations = $data['preparations'] ?? [];
            unset($data['ingredients'], $data['preparations']);
            $recipe = $product->recipe()->updateOrCreate([], $data);
            $recipe->ingredients()->delete();
            $recipe->preparations()->delete();
            foreach ($ingredients as $item) {
                $recipe->ingredients()->create($item);
            }
            foreach ($preparations as $item) {
                $recipe->preparations()->create($item);
            }

            return $recipe->refresh();
        });
    }
}
