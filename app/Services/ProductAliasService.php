<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductAlias;
use Illuminate\Validation\ValidationException;

class ProductAliasService
{
    public function __construct(private ProductMatchService $products) {}

    public function sync(Product $product, array $aliases): void
    {
        $normalizedProductNames = Product::query()
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Product $item) => [$this->products->normalize($item->name) => $item->id]);
        $normalizedProductName = $this->products->normalize($product->name);
        $duplicateOfficialName = Product::query()
            ->where('id', '!=', $product->id)
            ->get(['name'])
            ->contains(fn (Product $item) => $this->products->normalize($item->name) === $normalizedProductName);
        $conflictingAlias = ProductAlias::query()
            ->where('product_id', '!=', $product->id)
            ->where('normalized_name', $normalizedProductName)
            ->exists();
        if ($duplicateOfficialName || $conflictingAlias) {
            throw ValidationException::withMessages(['name' => 'O nome do produto coincide com outro produto ou alias cadastrado.']);
        }
        $prepared = collect($aliases)
            ->map(fn (string $name) => ['name' => trim($name), 'normalized_name' => $this->products->normalize($name)])
            ->filter(fn (array $alias) => $alias['normalized_name'] !== '')
            ->values();

        if ($prepared->pluck('normalized_name')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['aliases_text' => 'Informe cada alias apenas uma vez.']);
        }
        foreach ($prepared as $alias) {
            if ($normalizedProductNames->has($alias['normalized_name'])) {
                throw ValidationException::withMessages([
                    'aliases_text' => 'O alias "'.$alias['name'].'" coincide com o nome oficial de um produto.',
                ]);
            }
        }

        $otherAliasExists = $prepared->contains(fn (array $alias) => ProductAlias::query()
            ->where('normalized_name', $alias['normalized_name'])
            ->where('product_id', '!=', $product->id)
            ->exists());
        if ($otherAliasExists) {
            throw ValidationException::withMessages(['aliases_text' => 'Um dos aliases já pertence a outro produto.']);
        }

        $product->aliases()->delete();
        $product->aliases()->createMany($prepared->all());
    }
}
