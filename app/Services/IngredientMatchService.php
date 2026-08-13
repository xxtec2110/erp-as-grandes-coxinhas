<?php

namespace App\Services;

use App\Models\Ingredient;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;

class IngredientMatchService
{
    public function matchItems(array $items): array
    {
        $ingredients = Ingredient::query()->with('currentPrice')->where('active', true)->get();

        return array_map(function (array $item) use ($ingredients): array {
            $name = (string) ($item['ingredient_name'] ?? $item['description'] ?? '');
            $exact = $ingredients->filter(fn (Ingredient $ingredient) => $this->normalize($ingredient->name) === $this->normalize($name));
            if ($exact->count() !== 1) {
                return [...$item, '_ingredient_match' => ['status' => $exact->count() > 1 ? 'ambiguous' : 'not_found']];
            }
            $ingredient = $exact->first();
            $match = ['status' => 'exact', 'ingredient_id' => $ingredient->id, 'current_base_unit_cost' => $ingredient->currentPrice?->base_unit_cost];
            if (isset($item['base_unit_cost']) && $ingredient->currentPrice !== null) {
                $match['found_base_unit_cost'] = (string) $item['base_unit_cost'];
                $match['difference'] = (string) BigDecimal::of((string) $item['base_unit_cost'])->minus($ingredient->currentPrice->base_unit_cost);
            }

            return [...$item, 'ingredient_id' => $ingredient->id, '_ingredient_match' => $match];
        }, array_values(array_filter($items, 'is_array')));
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(Str::ascii(trim($value)))) ?? '';
    }
}
