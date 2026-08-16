<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductCatalogService
{
    public function __construct(private ProductMatchService $matching, private ProductAliasService $aliases, private ProductPriceService $prices) {}

    public function create(array $data, array $aliases = [], ?User $user = null, string $source = 'web', ?string $idempotencyKey = null): Product
    {
        return DB::transaction(function () use ($data, $aliases, $user, $source, $idempotencyKey): Product {
            $this->assertUniqueName($data['name']);
            $price = $data['selling_price'] ?? null;
            unset($data['selling_price']);
            $product = Product::query()->create($data);
            $this->aliases->sync($product, $aliases);
            if ($price !== null) {
                $this->prices->record($product, (string) $price, $user, $source, $idempotencyKey ? $idempotencyKey.':price' : null);
            }

            return $product->load(['aliases', 'currentPrice', 'category']);
        });
    }

    public function update(Product $product, array $data, ?array $aliases = null, ?User $user = null, string $source = 'web', ?string $idempotencyKey = null): Product
    {
        return DB::transaction(function () use ($product, $data, $aliases, $user, $source, $idempotencyKey): Product {
            $this->assertUniqueName($data['name'] ?? $product->name, $product);
            $price = $data['selling_price'] ?? null;
            unset($data['selling_price']);
            if (isset($data['stock_unit']) && $data['stock_unit'] !== $product->stock_unit && $product->stockMovements()->exists()) {
                throw ValidationException::withMessages(['stock_unit' => 'A unidade de estoque não pode ser alterada após o primeiro movimento.']);
            }
            $product->update($data);
            if ($aliases !== null) {
                $this->aliases->sync($product, $aliases);
            }
            if ($price !== null) {
                $this->prices->record($product, (string) $price, $user, $source, $idempotencyKey ? $idempotencyKey.':price' : null);
            }

            return $product->load(['aliases', 'currentPrice', 'category']);
        });
    }

    public function syncOfficial(array $items, string $categoryName = 'Coxinhas'): array
    {
        return DB::transaction(function () use ($items, $categoryName): array {
            $category = ProductCategory::query()->get()->first(fn (ProductCategory $item) => $this->matching->normalize($item->name) === $this->matching->normalize($categoryName));
            $category ??= ProductCategory::query()->create(['name' => $categoryName, 'active' => true]);
            if (! $category->active) {
                $category->update(['active' => true]);
            }
            $created = 0;
            $updated = 0;
            foreach ($items as $item) {
                $product = Product::query()->get()->first(fn (Product $candidate) => $this->matching->normalize($candidate->name) === $this->matching->normalize($item['name']));
                $data = ['name' => $item['name'], 'product_category_id' => $category->id, 'stock_unit' => Product::UNIT_COUNT, 'sort_order' => $item['sort_order'], 'active' => true, 'selling_price' => $item['price']];
                if ($product === null) {
                    $this->create($data, [], null, 'official_local_import', 'official-product:'.$item['sort_order'].':'.$item['price']);
                    $created++;
                } else {
                    $before = [$product->name, $product->product_category_id, $product->stock_unit, $product->sort_order, $product->active, $product->currentPrice?->price];
                    $this->update($product, $data, null, null, 'official_local_import', 'official-product:'.$item['sort_order'].':'.$item['price']);
                    $afterProduct = $product->refresh()->load('currentPrice');
                    $after = [$afterProduct->name, $afterProduct->product_category_id, $afterProduct->stock_unit, $afterProduct->sort_order, $afterProduct->active, $afterProduct->currentPrice?->price];
                    if ($before !== $after) {
                        $updated++;
                    }
                }
            }

            return ['created' => $created, 'updated' => $updated, 'category_id' => $category->id];
        });
    }

    private function assertUniqueName(string $name, ?Product $except = null): void
    {
        $normalized = $this->matching->normalize($name);
        $duplicate = Product::query()->when($except, fn ($query) => $query->whereKeyNot($except->id))->get()
            ->contains(fn (Product $product) => $this->matching->normalize($product->name) === $normalized);
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'Já existe um produto com este nome normalizado.']);
        }
    }
}
