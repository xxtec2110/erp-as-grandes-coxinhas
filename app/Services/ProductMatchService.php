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
            $needle = Str::lower(Str::ascii(trim($item['product_name'])));
            $products = Product::query()->where('active', true)->get();
            $exact = $products->filter(fn ($p) => Str::lower(Str::ascii($p->name)) === $needle);
            $candidates = $products->filter(fn ($p) => str_contains(Str::lower(Str::ascii($p->name)), $needle) || str_contains($needle, Str::lower(Str::ascii($p->name))))->take(5);
            if ($exact->count() === 1 && $candidates->count() === 1) {
                $item['product_id'] = $exact->sole()->id;

                return $item;
            }
            $item['_product_match'] = ['status' => $candidates->count() === 1 ? 'approximate' : 'ambiguous', 'candidates' => $candidates->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all()];

            return $item;
        }, $items);
    }
}
