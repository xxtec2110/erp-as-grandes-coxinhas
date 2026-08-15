<?php

namespace App\Services;

use App\Models\Ingredient;
use Brick\Math\BigDecimal;

class IngredientMatchService
{
    public function __construct(private IngredientSemanticResolver $semantics) {}

    public function matchItems(array $items): array
    {
        return array_map(function (array $item): array {
            $name = (string) ($item['ingredient_name'] ?? $item['description'] ?? '');
            $brand = $item['ingredient_brand'] ?? $item['brand'] ?? null;
            $brandExplicit = filter_var($item['ingredient_brand_explicit'] ?? false, FILTER_VALIDATE_BOOL);
            $resolution = $this->semantics->resolve($name, is_string($brand) ? $brand : null, $brandExplicit);
            if ($resolution['business_term'] !== null && ! $brandExplicit) {
                unset($item['ingredient_brand'], $item['brand']);
            }
            $semantic = collect($resolution)->except('ingredient')->all();
            if ($resolution['status'] !== 'resolved') {
                $status = $resolution['status'] === 'target_missing' ? 'not_found' : $resolution['status'];

                return [...$item, 'ingredient_concept' => $resolution['concept_label'], '_ingredient_semantic' => $semantic, '_ingredient_match' => ['status' => $status, 'candidates' => $resolution['candidates'] ?? []]];
            }

            /** @var Ingredient $ingredient */
            $ingredient = $resolution['ingredient'];
            $match = ['status' => 'exact', 'ingredient_id' => $ingredient->id, 'current_base_unit_cost' => $ingredient->currentPrice?->base_unit_cost];
            if (isset($item['base_unit_cost']) && $ingredient->currentPrice !== null) {
                $match['found_base_unit_cost'] = (string) $item['base_unit_cost'];
                $match['difference'] = (string) BigDecimal::of((string) $item['base_unit_cost'])->minus($ingredient->currentPrice->base_unit_cost);
            }

            return [...$item, 'ingredient_id' => $ingredient->id, 'ingredient_concept' => $resolution['concept_label'], '_ingredient_semantic' => $semantic, '_ingredient_match' => $match];
        }, array_values(array_filter($items, 'is_array')));
    }
}
