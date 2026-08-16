<?php

namespace App\Services;

use App\Models\Ingredient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IngredientCatalogService
{
    public function __construct(private IngredientSemanticResolver $semantics) {}

    public function create(array $data): Ingredient
    {
        return DB::transaction(function () use ($data): Ingredient {
            $this->assertValidName($data['name']);

            return Ingredient::query()->create($data);
        });
    }

    public function update(Ingredient $ingredient, array $data): Ingredient
    {
        return DB::transaction(function () use ($ingredient, $data): Ingredient {
            $this->assertValidName($data['name'] ?? $ingredient->name, $ingredient);
            if (isset($data['base_unit']) && $data['base_unit'] !== $ingredient->base_unit && $ingredient->prices()->exists()) {
                throw ValidationException::withMessages(['base_unit' => 'A unidade-base não pode ser alterada depois que o insumo possui histórico de preços.']);
            }
            $ingredient->update($data);

            return $ingredient->refresh();
        });
    }

    private function assertValidName(string $name, ?Ingredient $except = null): void
    {
        if ($this->semantics->isProtectedBusinessTerm($name)) {
            throw ValidationException::withMessages(['name' => 'Use Requeijão como insumo; Catupiry é termo comercial protegido.']);
        }
        $normalized = Str::lower(Str::ascii(Str::squish($name)));
        $duplicate = Ingredient::query()->when($except, fn ($query) => $query->whereKeyNot($except->id))->get()
            ->contains(fn (Ingredient $ingredient) => Str::lower(Str::ascii(Str::squish($ingredient->name))) === $normalized);
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'Já existe um insumo com este nome.']);
        }
    }
}
