<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductCostSnapshot;
use App\Models\User;
use Illuminate\Support\Collection;

class CostQueryService
{
    public function __construct(private AuthorizationService $authorization, private IngredientPriceAnalyticsService $ingredients, private ProductMarginService $margins) {}

    public function ingredientCurrent(User $user, int $ingredientId): array
    {
        $this->authorization->authorize($user, 'ingredients.view');
        $ingredient = Ingredient::query()->with(['currentPrice.supplier'])->findOrFail($ingredientId);

        return ['ingredient' => $ingredient, 'current_price' => $ingredient->currentPrice, 'statistics_30_days' => $this->ingredients->summary($ingredient)];
    }

    public function ingredientHistory(User $user, int $ingredientId): Collection
    {
        $this->authorization->authorize($user, 'ingredients.view');

        return Ingredient::query()->findOrFail($ingredientId)->prices()->with(['supplier', 'purchaseDocument'])->latest('effective_date')->get();
    }

    public function compareSuppliers(User $user, int $ingredientId): Collection
    {
        $this->authorization->authorize($user, 'ingredients.view');

        return $this->ingredients->suppliers(Ingredient::query()->findOrFail($ingredientId));
    }

    public function productCurrent(User $user, int $productId): array
    {
        $this->authorization->authorize($user, 'products.view');

        return $this->margins->current(Product::query()->with(['recipe', 'currentPrice'])->findOrFail($productId));
    }

    public function productHistory(User $user, int $productId): Collection
    {
        $this->authorization->authorize($user, 'products.view');

        return ProductCostSnapshot::query()->where('product_id', $productId)->latest('effective_at')->get();
    }

    public function productMargin(User $user, int $productId, ?string $date = null): array
    {
        $this->authorization->authorize($user, 'products.view');
        $product = Product::query()->with(['recipe', 'currentPrice'])->findOrFail($productId);

        return $date === null ? $this->margins->current($product) : $this->margins->historical($product, $date);
    }
}
