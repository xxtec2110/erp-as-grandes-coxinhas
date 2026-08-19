<?php

namespace App\Services;

use App\Models\Preparation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class PreparationCostService
{
    public function __construct(private UnitConversionService $unitConversion) {}

    /** @return array<string, mixed> */
    public function calculate(Preparation $preparation): array
    {
        $preparation->load([
            'preparationIngredients.ingredient.currentPrice',
            'energyUsages.equipment',
            'energyUsages.burner',
            'energyUsages.glpProduct.currentPrice',
            'additionalCosts',
        ]);
        $total = BigDecimal::zero();
        $missingPrices = 0;
        $missingComponents = [];
        $ingredients = [];

        if ($preparation->preparationIngredients->isEmpty()) {
            $missingComponents[] = 'Nenhum ingrediente cadastrado';
        }

        foreach ($preparation->preparationIngredients as $item) {
            $normalizedQuantity = $this->unitConversion->normalize(
                $item->quantity,
                $item->unit,
                $item->ingredient->base_unit,
            );
            $currentPrice = $item->ingredient->currentPrice;
            $itemCost = null;

            if ($currentPrice === null) {
                $missingPrices++;
                $missingComponents[] = 'Insumo: '.$item->ingredient->name;
            } else {
                $itemCost = (string) BigDecimal::of($currentPrice->base_unit_cost)
                    ->multipliedBy($normalizedQuantity)
                    ->toScale(8, RoundingMode::HalfUp);
                $total = $total->plus($itemCost);
            }

            $ingredients[] = [
                'item' => $item,
                'normalized_quantity' => $normalizedQuantity,
                'base_unit_cost' => $currentPrice?->base_unit_cost,
                'total_cost' => $itemCost,
            ];
        }

        $totalIngredientsCost = (string) $total->toScale(8, RoundingMode::HalfUp);
        $energy = $this->calculateEnergy($preparation);
        $additionalCosts = $this->calculateAdditionalCosts($preparation);
        $totalPreparationCost = (string) BigDecimal::of($totalIngredientsCost)
            ->plus($energy['total_cost'])
            ->plus($additionalCosts['total_cost'])
            ->toScale(8, RoundingMode::HalfUp);
        $missingPriceCount = $missingPrices + $energy['missing_price_count'];
        $missingComponents = array_values(array_unique([
            ...$missingComponents,
            ...$energy['missing_components'],
        ]));
        if ($preparation->actual_final_quantity === null) {
            $missingComponents[] = 'Quantidade final real não informada';
        }
        $isComplete = $missingPriceCount === 0 && $missingComponents === [];

        return [
            'ingredients' => $ingredients,
            'total_ingredients_cost' => $totalIngredientsCost,
            'energy_usages' => $energy['usages'],
            'total_glp_consumption_kg' => $energy['total_consumption_kg'],
            'total_energy_cost' => $energy['total_cost'],
            'additional_costs' => $additionalCosts['items'],
            'total_additional_costs' => $additionalCosts['total_cost'],
            'total_preparation_cost' => $totalPreparationCost,
            'missing_ingredient_price_count' => $missingPrices,
            'missing_glp_price_count' => $energy['missing_price_count'],
            'missing_price_count' => $missingPriceCount,
            'missing_components' => $missingComponents,
            'is_complete' => $isComplete,
            'yield' => $this->calculateYield($preparation),
            'unit_costs' => $isComplete
                ? $this->calculateFinalUnitCosts($preparation, $totalPreparationCost)
                : null,
        ];
    }

    /** @return array{usages: array<int, array<string, mixed>>, total_consumption_kg: string, total_cost: string, missing_price_count: int, missing_components: array<int, string>} */
    private function calculateEnergy(Preparation $preparation): array
    {
        $totalConsumption = BigDecimal::zero();
        $totalCost = BigDecimal::zero();
        $missingPrices = 0;
        $missingComponents = [];
        $usages = [];

        foreach ($preparation->energyUsages as $usage) {
            $nominalConsumption = $usage->burner?->nominal_glp_consumption_kg_hour
                ?? $usage->equipment->nominal_glp_consumption_kg_hour;
            $consumption = BigDecimal::of($nominalConsumption)
                ->multipliedBy($usage->usage_time_minutes)
                ->dividedBy(60, 12, RoundingMode::HalfUp)
                ->multipliedBy($usage->utilization_factor)
                ->toScale(8, RoundingMode::HalfUp);
            $currentPrice = $usage->glpProduct->currentPrice;
            $cost = null;
            $totalConsumption = $totalConsumption->plus($consumption);

            if ($currentPrice === null) {
                $missingPrices++;
                $missingComponents[] = 'GLP: '.$usage->glpProduct->name;
            } else {
                $cost = (string) $consumption
                    ->multipliedBy($currentPrice->unit_cost_per_kg)
                    ->toScale(8, RoundingMode::HalfUp);
                $totalCost = $totalCost->plus($cost);
            }

            $usages[] = [
                'usage' => $usage,
                'nominal_consumption_kg_hour' => $nominalConsumption,
                'consumption_kg' => (string) $consumption,
                'glp_unit_cost' => $currentPrice?->unit_cost_per_kg,
                'cost' => $cost,
            ];
        }

        return [
            'usages' => $usages,
            'total_consumption_kg' => (string) $totalConsumption->toScale(8, RoundingMode::HalfUp),
            'total_cost' => (string) $totalCost->toScale(8, RoundingMode::HalfUp),
            'missing_price_count' => $missingPrices,
            'missing_components' => array_values(array_unique($missingComponents)),
        ];
    }

    /** @return array{items: mixed, total_cost: string} */
    private function calculateAdditionalCosts(Preparation $preparation): array
    {
        $total = BigDecimal::zero();

        foreach ($preparation->additionalCosts as $cost) {
            $total = $total->plus($cost->amount);
        }

        return [
            'items' => $preparation->additionalCosts,
            'total_cost' => (string) $total->toScale(8, RoundingMode::HalfUp),
        ];
    }

    /** @return array<string, string>|null */
    private function calculateYield(Preparation $preparation): ?array
    {
        if ($preparation->initial_quantity === null || $preparation->actual_final_quantity === null) {
            return null;
        }

        $initial = BigDecimal::of($this->unitConversion->normalizeToBase(
            $preparation->initial_quantity,
            $preparation->initial_unit,
        ));
        $final = BigDecimal::of($this->unitConversion->normalizeToBase(
            $preparation->actual_final_quantity,
            $preparation->yield_unit,
        ));
        $loss = $initial->minus($final);

        return [
            'base_unit' => $this->unitConversion->baseUnitFor($preparation->initial_unit),
            'initial' => (string) $initial->toScale(6, RoundingMode::HalfUp),
            'final' => (string) $final->toScale(6, RoundingMode::HalfUp),
            'loss' => (string) $loss->toScale(6, RoundingMode::HalfUp),
            'loss_percentage' => (string) $loss->multipliedBy(100)
                ->dividedBy($initial, 4, RoundingMode::HalfUp),
            'yield_percentage' => (string) $final->multipliedBy(100)
                ->dividedBy($initial, 4, RoundingMode::HalfUp),
        ];
    }

    /** @return array<string, string>|null */
    private function calculateFinalUnitCosts(Preparation $preparation, string $totalCost): ?array
    {
        if ($preparation->actual_final_quantity === null) {
            return null;
        }

        $baseUnit = $this->unitConversion->baseUnitFor($preparation->yield_unit);
        $finalQuantity = $this->unitConversion->normalize(
            $preparation->actual_final_quantity,
            $preparation->yield_unit,
            $baseUnit,
        );
        $baseCost = $this->unitConversion->calculateBaseUnitCost($totalCost, $finalQuantity);

        return [
            'base_unit' => $baseUnit,
            'base_unit_cost' => $baseCost,
            'large_unit' => match ($baseUnit) {
                'g' => 'kg',
                'ml' => 'l',
                default => null,
            },
            'large_unit_cost' => in_array($baseUnit, ['g', 'ml'], true)
                ? $this->unitConversion->costForDisplayUnit($baseCost, $baseUnit === 'g' ? 'kg' : 'l')
                : null,
        ];
    }
}
