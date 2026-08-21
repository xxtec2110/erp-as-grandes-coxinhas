<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class MoneyAllocationService
{
    /**
     * @param  array<int, string>  $weights
     * @param  array<int, string|int>  $stableKeys
     * @return array<int, string>
     */
    public function allocate(string $amount, array $weights, array $stableKeys = []): array
    {
        $money = BigDecimal::of($amount)->toScale(2, RoundingMode::Unnecessary);
        if ($money->isNegative()) {
            throw new DomainException('O valor a distribuir não pode ser negativo.');
        }
        if ($weights === []) {
            if ($money->isZero()) {
                return [];
            }

            throw new DomainException('Não existem destinos para distribuir o valor.');
        }

        $normalized = array_map(function (string $weight): BigDecimal {
            $value = BigDecimal::of($weight);
            if ($value->isNegative()) {
                throw new DomainException('O peso da distribuição não pode ser negativo.');
            }

            return $value;
        }, $weights);
        $totalWeight = array_reduce($normalized, fn (BigDecimal $sum, BigDecimal $weight): BigDecimal => $sum->plus($weight), BigDecimal::zero());
        if ($totalWeight->isZero()) {
            if ($money->isZero()) {
                return array_fill(0, count($weights), '0.00');
            }

            throw new DomainException('A distribuição exige ao menos um peso positivo.');
        }

        $amountCents = $money->multipliedBy(100)->toInt();
        $allocatedCents = [];
        $remainders = [];
        $allocated = 0;
        foreach ($normalized as $index => $weight) {
            $raw = BigDecimal::of($amountCents)->multipliedBy($weight)->dividedBy($totalWeight, 18, RoundingMode::Down);
            $floor = $raw->toScale(0, RoundingMode::Down)->toInt();
            $allocatedCents[$index] = $floor;
            $remainders[$index] = $raw->minus($floor);
            $allocated += $floor;
        }

        $order = array_keys($weights);
        usort($order, function (int $left, int $right) use ($remainders, $stableKeys): int {
            $comparison = $remainders[$right]->compareTo($remainders[$left]);
            if ($comparison !== 0) {
                return $comparison;
            }

            return strcmp((string) ($stableKeys[$left] ?? $left), (string) ($stableKeys[$right] ?? $right));
        });

        $remaining = $amountCents - $allocated;
        for ($position = 0; $position < $remaining; $position++) {
            $allocatedCents[$order[$position % count($order)]]++;
        }
        ksort($allocatedCents);

        return array_map(
            fn (int $cents): string => (string) BigDecimal::of($cents)->dividedBy(100, 2, RoundingMode::Unnecessary),
            $allocatedCents,
        );
    }
}
