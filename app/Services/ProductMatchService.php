<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Str;

class ProductMatchService
{
    public function matchItems(array $items): array
    {
        return array_map(function (array $item) {
            if (isset($item['product_id']) || ! isset($item['product_name'])) {
                return $item;
            }
            $needle = $this->normalize($item['product_name']);
            $products = Product::query()->with('aliases')->where('active', true)->get();
            $exact = $products->filter(fn (Product $product) => $this->normalize($product->name) === $needle);
            $aliasExact = $products->filter(fn (Product $product) => $product->aliases->contains(
                fn ($alias) => $alias->normalized_name === $needle
            ));
            $candidates = $products->filter(function (Product $product) use ($needle): bool {
                $name = $this->normalize($product->name);

                return str_contains($name, $needle) || str_contains($needle, $name)
                    || $product->aliases->contains(fn ($alias) => str_contains($alias->normalized_name, $needle) || str_contains($needle, $alias->normalized_name));
            })->take(5);
            if ($exact->count() === 1 && $candidates->count() === 1) {
                $item['product_id'] = $exact->sole()->id;

                return $item;
            }
            if ($exact->isEmpty() && $aliasExact->count() === 1) {
                $item['product_id'] = $aliasExact->sole()->id;

                return $item;
            }
            $item['_product_match'] = ['status' => $candidates->count() === 1 ? 'approximate' : 'ambiguous', 'candidates' => $candidates->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all()];

            return $item;
        }, $items);
    }

    public function resolveExactItems(array $items): array
    {
        $products = Product::query()->with('aliases')->get();

        return array_map(function (array $item) use ($products): array {
            if (isset($item['product_id'])) {
                $product = $products->firstWhere('id', (int) $item['product_id']);

                return $this->resolvedItem($item, $product, 'explicit');
            }

            $needle = $this->normalize((string) ($item['product_name'] ?? ''));
            $exact = $products->filter(fn (Product $product) => $this->normalize($product->name) === $needle);
            if ($exact->count() !== 1) {
                if ($exact->count() > 1) {
                    return $this->unresolvedItem($item, 'ambiguous');
                }

                $aliases = $products->filter(fn (Product $product) => $product->aliases->contains(
                    fn ($alias) => $alias->normalized_name === $needle
                ));
                if ($aliases->count() !== 1) {
                    return $this->unresolvedItem($item, $aliases->count() > 1 ? 'ambiguous' : 'not_found');
                }
                $product = $aliases->sole();

                return $this->resolvedItem($item, $product, 'alias');
            }

            return $this->resolvedItem($item, $exact->sole(), 'official_name');
        }, $items);
    }

    public function normalize(string $name): string
    {
        $ascii = Str::lower(Str::ascii($name));

        return Str::squish(preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '');
    }

    private function resolvedItem(array $item, ?Product $product, string $matchedBy): array
    {
        if ($product === null) {
            return $this->unresolvedItem($item, 'not_found');
        }
        if (! $product->active) {
            return $this->unresolvedItem($item, 'inactive');
        }

        return [
            ...$item,
            'product_id' => $product->id,
            'product_name' => $product->name,
            '_product_match' => ['status' => 'resolved', 'matched_by' => $matchedBy],
        ];
    }

    private function unresolvedItem(array $item, string $status): array
    {
        unset($item['product_id']);
        $item['_product_match'] = ['status' => $status];

        return $item;
    }
}
