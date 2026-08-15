<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientBusinessTerm;
use App\Models\IngredientConcept;
use App\Models\IngredientConceptBinding;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IngredientSemanticResolver
{
    public function __construct(private AuthorizationService $authorization) {}

    public function resolve(string $term, ?string $brand = null, bool $brandExplicit = false): array
    {
        $normalized = $this->normalize($term);
        $businessTerm = IngredientBusinessTerm::query()
            ->with('concept')
            ->where('normalized_term', $normalized)
            ->where('active', true)
            ->first();

        if ($businessTerm === null) {
            return $this->resolveOrdinaryIngredient($term, $brand, $brandExplicit);
        }

        $concept = $businessTerm->concept;
        $safeBrand = $brandExplicit && filled($brand) ? trim((string) $brand) : null;
        $base = [
            'original_term' => trim($term),
            'normalized_term' => $normalized,
            'business_term' => $businessTerm->term,
            'concept' => $concept->code,
            'concept_label' => $concept->name,
            'brand' => $safeBrand,
            'brand_explicit' => $safeBrand !== null,
            'protected' => $businessTerm->is_protected || $concept->is_protected,
        ];

        if (! $concept->active) {
            return [...$base, 'status' => 'inactive', 'ingredient_id' => null, 'message' => 'O conceito de ingrediente está inativo.'];
        }

        $binding = IngredientConceptBinding::query()
            ->with('ingredient.currentPrice')
            ->where('ingredient_concept_id', $concept->id)
            ->whereNull('effective_until')
            ->latest('effective_from')
            ->first();

        if ($binding !== null && $binding->ingredient->active && ($safeBrand === null || $this->normalize((string) $binding->ingredient->brand) === $this->normalize($safeBrand))) {
            return $this->resolved($base, $binding->ingredient, 'explicit_binding');
        }

        $matches = $this->conceptCandidates($concept, $safeBrand);
        $active = $matches->where('active', true)->values();
        if ($active->count() === 1) {
            return $this->resolved($base, $active->first(), $safeBrand === null ? 'single_exact_concept' : 'explicit_brand');
        }
        if ($active->count() > 1) {
            return [...$base, 'status' => 'ambiguous', 'ingredient_id' => null, 'candidates' => $this->candidatePayload($active), 'message' => 'Existem vários cadastros compatíveis. Um administrador precisa definir o vínculo operacional.'];
        }
        if ($matches->isNotEmpty() || ($binding !== null && ! $binding->ingredient->active)) {
            return [...$base, 'status' => 'inactive', 'ingredient_id' => null, 'message' => 'O cadastro operacional correspondente está inativo.'];
        }

        return [...$base, 'status' => 'target_missing', 'ingredient_id' => null, 'message' => "Entendi '{$businessTerm->term}' como {$concept->name}, mas ainda preciso saber qual cadastro de {$concept->name} deve ser utilizado."];
    }

    public function isProtectedBusinessTerm(string $value): bool
    {
        $term = IngredientBusinessTerm::query()
            ->with('concept')
            ->where('normalized_term', $this->normalize($value))
            ->where('is_protected', true)
            ->first();

        return $term !== null && $term->normalized_term !== $this->normalize($term->concept->name);
    }

    /** @throws AuthorizationException */
    public function bind(IngredientConcept $concept, Ingredient $ingredient, User $actor, ?string $reason = null): IngredientConceptBinding
    {
        $this->authorization->authorize($actor, 'ingredients.update');
        if (! $ingredient->active) {
            throw new DomainException('O vínculo operacional exige um insumo ativo.');
        }

        return DB::transaction(function () use ($concept, $ingredient, $actor, $reason): IngredientConceptBinding {
            $concept = IngredientConcept::query()->lockForUpdate()->findOrFail($concept->id);
            if (! $concept->active) {
                throw new DomainException('O conceito de ingrediente está inativo.');
            }

            $current = IngredientConceptBinding::query()
                ->where('ingredient_concept_id', $concept->id)
                ->whereNull('effective_until')
                ->lockForUpdate()
                ->first();
            if ($current?->ingredient_id === $ingredient->id) {
                return $current;
            }

            $effectiveAt = now();
            $current?->update(['effective_until' => $effectiveAt]);

            return IngredientConceptBinding::query()->create([
                'ingredient_concept_id' => $concept->id,
                'ingredient_id' => $ingredient->id,
                'effective_from' => $effectiveAt,
                'created_by' => $actor->id,
                'reason' => $reason,
            ]);
        });
    }

    public function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(Str::ascii(trim($value)))) ?? '';
    }

    private function resolveOrdinaryIngredient(string $term, ?string $brand, bool $brandExplicit): array
    {
        $normalized = $this->normalize($term);
        $safeBrand = $brandExplicit && filled($brand) ? trim((string) $brand) : null;
        $matches = Ingredient::query()->with('currentPrice')->get()->filter(
            fn (Ingredient $ingredient) => $this->normalize($ingredient->name) === $normalized
                && ($safeBrand === null || $this->normalize((string) $ingredient->brand) === $this->normalize($safeBrand))
        )->values();
        $base = ['original_term' => trim($term), 'normalized_term' => $normalized, 'business_term' => null, 'concept' => null, 'concept_label' => null, 'brand' => $safeBrand, 'brand_explicit' => $safeBrand !== null, 'protected' => false];
        $active = $matches->where('active', true)->values();
        if ($active->count() === 1) {
            return $this->resolved($base, $active->first(), 'exact_name');
        }
        if ($active->count() > 1) {
            return [...$base, 'status' => 'ambiguous', 'ingredient_id' => null, 'candidates' => $this->candidatePayload($active), 'message' => 'Existem vários insumos com esse nome.'];
        }

        return [...$base, 'status' => $matches->isNotEmpty() ? 'inactive' : 'target_missing', 'ingredient_id' => null, 'message' => $matches->isNotEmpty() ? 'O insumo correspondente está inativo.' : 'Insumo não encontrado.'];
    }

    private function conceptCandidates(IngredientConcept $concept, ?string $brand): Collection
    {
        return Ingredient::query()->with('currentPrice')->get()->filter(
            fn (Ingredient $ingredient) => $this->normalize($ingredient->name) === $this->normalize($concept->name)
                && ($brand === null || $this->normalize((string) $ingredient->brand) === $this->normalize($brand))
        )->values();
    }

    private function resolved(array $base, Ingredient $ingredient, string $source): array
    {
        return [...$base, 'status' => 'resolved', 'ingredient_id' => $ingredient->id, 'ingredient_name' => $ingredient->name, 'ingredient_brand' => $ingredient->brand, 'resolution_source' => $source, 'ingredient' => $ingredient];
    }

    private function candidatePayload(Collection $ingredients): array
    {
        return $ingredients->map(fn (Ingredient $ingredient) => ['id' => $ingredient->id, 'name' => $ingredient->name, 'brand' => $ingredient->brand])->all();
    }
}
