<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

class UnitConversionService
{
    /** @var array<string, string> */
    private const UNIT_FAMILIES = [
        'kg' => 'weight',
        'g' => 'weight',
        'l' => 'volume',
        'ml' => 'volume',
        'un' => 'unit',
    ];

    /** @var array<string, string> */
    private const BASE_UNITS = [
        'weight' => 'g',
        'volume' => 'ml',
        'unit' => 'un',
    ];

    public function normalize(string $quantity, string $purchaseUnit, string $baseUnit): string
    {
        $this->assertCompatible($purchaseUnit, $baseUnit);

        $value = BigDecimal::of($quantity);

        if ($value->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new InvalidArgumentException('A quantidade deve ser maior que zero.');
        }

        $normalized = match ($purchaseUnit) {
            'kg', 'l' => $value->multipliedBy(1000),
            default => $value,
        };

        return (string) $normalized->toScale(6, RoundingMode::HalfUp);
    }

    public function normalizeToBase(string $quantity, string $unit): string
    {
        return $this->normalize($quantity, $unit, $this->baseUnitFor($unit));
    }

    public function baseUnitFor(string $unit): string
    {
        $family = self::UNIT_FAMILIES[$unit] ?? null;
        $baseUnit = $family === null ? null : (self::BASE_UNITS[$family] ?? null);

        if ($baseUnit === null) {
            throw new InvalidArgumentException('Unidade inválida.');
        }

        return $baseUnit;
    }

    public function areCompatible(string $firstUnit, string $secondUnit): bool
    {
        return isset(self::UNIT_FAMILIES[$firstUnit], self::UNIT_FAMILIES[$secondUnit])
            && self::UNIT_FAMILIES[$firstUnit] === self::UNIT_FAMILIES[$secondUnit];
    }

    public function calculateBaseUnitCost(string $pricePaid, string $normalizedQuantity): string
    {
        $price = BigDecimal::of($pricePaid);
        $quantity = BigDecimal::of($normalizedQuantity);

        if ($price->isLessThan(BigDecimal::zero()) || $quantity->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new InvalidArgumentException('Preço e quantidade devem ser valores válidos.');
        }

        return (string) $price->dividedBy($quantity, 8, RoundingMode::HalfUp);
    }

    public function costForDisplayUnit(string $baseUnitCost, string $displayUnit): string
    {
        $baseUnit = self::BASE_UNITS[self::UNIT_FAMILIES[$displayUnit] ?? ''] ?? null;

        if ($baseUnit === null) {
            throw new InvalidArgumentException('Unidade de exibição inválida.');
        }

        $this->assertCompatible($displayUnit, $baseUnit);

        $cost = BigDecimal::of($baseUnitCost);

        if (in_array($displayUnit, ['kg', 'l'], true)) {
            $cost = $cost->multipliedBy(1000);
        }

        return (string) $cost->toScale(4, RoundingMode::HalfUp);
    }

    /** @return array<int, string> */
    public function allowedPurchaseUnits(string $baseUnit): array
    {
        return match ($baseUnit) {
            'g' => ['kg', 'g'],
            'ml' => ['l', 'ml'],
            'un' => ['un'],
            default => [],
        };
    }

    private function assertCompatible(string $purchaseUnit, string $baseUnit): void
    {
        $purchaseFamily = self::UNIT_FAMILIES[$purchaseUnit] ?? null;
        $baseFamily = self::UNIT_FAMILIES[$baseUnit] ?? null;

        if ($purchaseFamily === null || $baseFamily === null || $purchaseFamily !== $baseFamily) {
            throw new InvalidArgumentException('A unidade da compra é incompatível com a unidade-base do insumo.');
        }

        if ((self::BASE_UNITS[$baseFamily] ?? null) !== $baseUnit) {
            throw new InvalidArgumentException('A unidade-base deve ser g, ml ou un.');
        }
    }
}
