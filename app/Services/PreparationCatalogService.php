<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Preparation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PreparationCatalogService
{
    public function __construct(private UnitConversionService $units) {}

    public function create(array $data): Preparation
    {
        return DB::transaction(function () use ($data): Preparation {
            $ingredients = $data['ingredients'] ?? [];
            unset($data['ingredients']);
            $this->validateIngredients($ingredients);
            $preparation = Preparation::query()->create($data);
            $preparation->preparationIngredients()->createMany($ingredients);

            return $preparation->load('preparationIngredients');
        });
    }

    public function update(Preparation $preparation, array $data): Preparation
    {
        return DB::transaction(function () use ($preparation, $data): Preparation {
            $replaceIngredients = array_key_exists('ingredients', $data);
            $ingredients = $data['ingredients'] ?? [];
            unset($data['ingredients']);
            $this->validateIngredients($ingredients);
            $preparation->update($data);
            if ($replaceIngredients) {
                $preparation->preparationIngredients()->delete();
                $preparation->preparationIngredients()->createMany($ingredients);
            }

            return $preparation->refresh()->load('preparationIngredients');
        });
    }

    private function validateIngredients(array $items): void
    {
        foreach ($items as $index => $item) {
            $ingredient = Ingredient::query()->findOrFail($item['ingredient_id']);
            if (! $this->units->areCompatible((string) $item['unit'], $ingredient->base_unit)) {
                throw ValidationException::withMessages(["ingredients.{$index}.unit" => 'A unidade não é compatível com a unidade-base do insumo.']);
            }
        }
    }
}
