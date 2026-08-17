<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\SupplierIngredientMapping;
use App\Models\User;
use Illuminate\Support\Str;

class SupplierIngredientMappingService
{
    public function __construct(private IngredientSemanticResolver $semantics) {}

    public function match(int $supplierId, ?string $externalCode, string $description): array
    {
        $query = SupplierIngredientMapping::query()->with('ingredient.currentPrice')
            ->where('supplier_id', $supplierId)->where('active', true);

        if (filled($externalCode)) {
            $mapping = (clone $query)->where('external_code', trim((string) $externalCode))->first();
            if ($mapping !== null) {
                return $this->resolved($mapping->ingredient, 'supplier_external_code', $mapping->id);
            }
        }

        $normalized = $this->normalize($description);
        $mapping = (clone $query)->where('normalized_description', $normalized)->first();
        if ($mapping !== null) {
            return $this->resolved($mapping->ingredient, 'supplier_description', $mapping->id);
        }

        $semantic = $this->semantics->resolve($description);
        if ($semantic['status'] === 'resolved') {
            return $this->resolved($semantic['ingredient'], $semantic['resolution_source'], null, $semantic);
        }

        return [
            'status' => $semantic['status'] === 'target_missing' ? 'not_found' : $semantic['status'],
            'ingredient_id' => null,
            'mapping_id' => null,
            'source' => 'suggestion_only',
            'candidates' => $semantic['candidates'] ?? [],
            'semantic' => collect($semantic)->except('ingredient')->all(),
        ];
    }

    public function confirm(int $supplierId, Ingredient $ingredient, ?string $externalCode, string $description, User $user): SupplierIngredientMapping
    {
        $normalized = $this->normalize($description);

        return SupplierIngredientMapping::query()->updateOrCreate(
            ['supplier_id' => $supplierId, 'normalized_description' => $normalized],
            ['ingredient_id' => $ingredient->id, 'external_code' => filled($externalCode) ? trim((string) $externalCode) : null, 'external_description' => trim($description), 'active' => true, 'created_by' => $user->id],
        );
    }

    public function normalize(string $value): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower(Str::ascii(trim($value))));

        return trim($normalized ?? '');
    }

    private function resolved(Ingredient $ingredient, string $source, ?int $mappingId, array $semantic = []): array
    {
        return ['status' => 'resolved', 'ingredient_id' => $ingredient->id, 'ingredient' => $ingredient, 'mapping_id' => $mappingId, 'source' => $source, 'candidates' => [], 'semantic' => collect($semantic)->except('ingredient')->all()];
    }
}
