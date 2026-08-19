<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Preparation;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProductRecipeService
{
    public function __construct(private ProductPriceService $prices, private ProductCostSnapshotService $costSnapshots, private UnitConversionService $units) {}

    public function save(Product $product, array $data, ?User $user = null, string $source = 'web', ?string $idempotencyKey = null): ProductRecipe
    {
        $this->assertComponentsAreValid($data);

        $recipe = DB::transaction(function () use ($product, $data, $user, $source, $idempotencyKey) {
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
        $this->costSnapshots->current($product->fresh(['recipe', 'currentPrice']), 'recipe', (string) $recipe->id);

        return $recipe;
    }

    /** @param array<string, mixed> $data */
    private function assertComponentsAreValid(array $data): void
    {
        $ingredients = collect($data['ingredients'] ?? []);
        $preparations = collect($data['preparations'] ?? []);
        if ($ingredients->pluck('ingredient_id')->duplicates()->isNotEmpty() || $preparations->pluck('preparation_id')->duplicates()->isNotEmpty()) {
            throw new DomainException('A ficha técnica não pode repetir o mesmo ingrediente ou preparo.');
        }
        foreach ($ingredients as $item) {
            $ingredient = Ingredient::query()->findOrFail($item['ingredient_id']);
            if (! $this->units->areCompatible((string) $item['unit'], $ingredient->base_unit)) {
                throw new DomainException('A unidade informada é incompatível com o insumo '.$ingredient->name.'.');
            }
        }
        foreach ($preparations as $item) {
            $preparation = Preparation::query()->findOrFail($item['preparation_id']);
            if (! $this->units->areCompatible((string) $item['unit'], $preparation->yield_unit)) {
                throw new DomainException('A unidade informada é incompatível com o rendimento do preparo '.$preparation->name.'.');
            }
        }
    }
}
