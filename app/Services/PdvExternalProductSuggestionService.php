<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;

class PdvExternalProductSuggestionService
{
    public const TYPE_EXACT = 'exact';

    public const TYPE_ALIAS = 'alias';

    public const TYPE_SIMILAR = 'similar';

    public const TYPE_NONE = 'none';

    public function __construct(private ProductMatchService $products) {}

    /** @param Collection<int, Product> $products @return array{product:?Product,type:string,score:int,confidence:string} */
    public function suggest(string $externalName, Collection $products): array
    {
        $needle = $this->normalizeCommercialName($externalName);
        $family = $this->externalFamily($externalName);
        $eligible = $products->filter(fn (Product $product): bool => $product->active && $this->familiesAreCompatible($family, $this->productFamily($product)));

        $exact = $eligible->filter(fn (Product $product): bool => $this->normalizeCommercialName($product->name) === $needle);
        if ($exact->count() === 1) {
            return $this->result($exact->sole(), self::TYPE_EXACT, 100, 'alta');
        }

        $alias = $eligible->filter(fn (Product $product): bool => $product->aliases->contains(
            fn ($item): bool => $this->normalizeCommercialName($item->name) === $needle
        ));
        if ($alias->count() === 1) {
            return $this->result($alias->sole(), self::TYPE_ALIAS, 100, 'alta');
        }

        $similar = $eligible->map(function (Product $product) use ($needle): array {
            $names = collect([$product->name])->merge($product->aliases->pluck('name'));
            $score = $names->max(fn (string $name): int => $this->similarity($needle, $this->normalizeCommercialName($name))) ?? 0;

            return compact('product', 'score');
        })->sortByDesc('score')->values();
        $best = $similar->first();
        $runnerUp = $similar->get(1);
        if ($best !== null && $best['score'] >= 55 && ($runnerUp === null || $best['score'] - $runnerUp['score'] >= 8)) {
            return $this->result($best['product'], self::TYPE_SIMILAR, $best['score'], $best['score'] >= 75 ? 'média' : 'baixa');
        }

        return $this->result(null, self::TYPE_NONE, 0, 'nenhuma');
    }

    public function normalizeCommercialName(string $name): string
    {
        $normalized = $this->products->normalize($name);
        $normalized = preg_replace('/^\d+\s*/', '', $normalized) ?? $normalized;
        $tokens = collect(explode(' ', $normalized))
            ->reject(fn (string $token): bool => in_array($token, ['coxinha', 'de', 'com'], true))
            ->map(fn (string $token): string => match ($token) {
                'mussarela', 'mozarela' => 'mucarela',
                'catupiri' => 'catupiry',
                default => $token,
            });

        return $tokens->implode(' ');
    }

    private function externalFamily(string $name): ?string
    {
        $normalized = $this->products->normalize($name);
        if (preg_match('/\b(agua|suco|coca|sprite|cerveja|refrigerante|lata|litro|ml)\b/', $normalized) === 1) {
            return 'beverage';
        }
        if (str_contains($normalized, 'coxinha')) {
            return 'coxinha';
        }

        return null;
    }

    private function productFamily(Product $product): ?string
    {
        $category = $this->products->normalize((string) $product->category?->name);
        if (str_contains($category, 'bebida')) {
            return 'beverage';
        }
        if (str_contains($category, 'coxinha')) {
            return 'coxinha';
        }

        return $this->externalFamily($product->name);
    }

    private function familiesAreCompatible(?string $external, ?string $internal): bool
    {
        return $external === null || $internal === null || $external === $internal;
    }

    private function similarity(string $left, string $right): int
    {
        if ($left === '' || $right === '') {
            return 0;
        }
        similar_text($left, $right, $percentage);
        $leftTokens = collect(explode(' ', $left))->unique();
        $rightTokens = collect(explode(' ', $right))->unique();
        $union = $leftTokens->merge($rightTokens)->unique()->count();
        $tokenScore = $union === 0 ? 0 : (int) round($leftTokens->intersect($rightTokens)->count() * 100 / $union);

        return max((int) round($percentage), $tokenScore);
    }

    /** @return array{product:?Product,type:string,score:int,confidence:string} */
    private function result(?Product $product, string $type, int $score, string $confidence): array
    {
        return compact('product', 'type', 'score', 'confidence');
    }
}
