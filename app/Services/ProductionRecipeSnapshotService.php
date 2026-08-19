<?php

namespace App\Services;

use App\Models\Product;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class ProductionRecipeSnapshotService
{
    public function __construct(private UnitConversionService $units, private ProductRecipeCostService $costs) {}

    public function capture(Product $product): array
    {
        $recipe = $product->recipe()->with(['ingredients.ingredient.currentPrice', 'preparations.preparation.preparationIngredients.ingredient.currentPrice'])->first();
        if ($recipe === null) {
            throw new DomainException("O produto {$product->name} não possui ficha técnica.");
        }
        $cost = $this->costs->calculate($recipe);
        if (! $cost['is_complete']) {
            throw new DomainException("A ficha técnica de {$product->name} está incompleta ou possui componentes sem preço atual.");
        }
        $yield = BigDecimal::of($recipe->yield_quantity);
        $consumption = [];
        foreach ($recipe->ingredients as $item) {
            $this->add($consumption, $item->ingredient, BigDecimal::of($this->units->normalize($item->quantity, $item->unit, $item->ingredient->base_unit))->dividedBy($yield, 12, RoundingMode::HalfUp), 'ingredient');
        }
        foreach ($recipe->preparations as $component) {
            $preparation = $component->preparation;
            $final = $preparation->actual_final_quantity ?? $preparation->expected_yield;
            if ($final === null) {
                throw new DomainException("O preparo {$preparation->name} não possui rendimento final.");
            }
            $componentBase = BigDecimal::of($this->units->normalizeToBase($component->quantity, $component->unit));
            $preparationBase = BigDecimal::of($this->units->normalizeToBase($final, $preparation->yield_unit));
            $ratio = $componentBase->dividedBy($preparationBase, 12, RoundingMode::HalfUp)->dividedBy($yield, 12, RoundingMode::HalfUp);
            foreach ($preparation->preparationIngredients as $item) {
                $this->add($consumption, $item->ingredient, BigDecimal::of($this->units->normalize($item->quantity, $item->unit, $item->ingredient->base_unit))->multipliedBy($ratio), 'preparation', $preparation->name);
            }
        }
        $loss = BigDecimal::of($recipe->technical_loss_percentage);
        if ($loss->isPositive()) {
            $factor = BigDecimal::one()->dividedBy(BigDecimal::one()->minus($loss->dividedBy(100, 12, RoundingMode::HalfUp)), 12, RoundingMode::HalfUp);
            foreach ($consumption as &$row) {
                $row['quantity'] = (string) BigDecimal::of($row['quantity'])->multipliedBy($factor)->toScale(8, RoundingMode::HalfUp);
            }
            unset($row);
        }

        return ['version' => 1, 'captured_at' => now()->toIso8601String(), 'product' => ['id' => $product->id, 'name' => $product->name, 'stock_unit' => $product->stock_unit], 'recipe' => ['id' => $recipe->id, 'yield_quantity' => $recipe->yield_quantity, 'final_weight_grams' => $recipe->final_weight_grams, 'technical_loss_percentage' => $recipe->technical_loss_percentage, 'packaging_cost' => $recipe->packaging_cost], 'consumption_per_product' => array_values($consumption), 'unit_cost' => $cost['unit_cost']];
    }

    private function add(array &$rows, object $ingredient, BigDecimal $quantity, string $origin, ?string $preparation = null): void
    {
        $key = (string) $ingredient->id;
        $current = BigDecimal::of($rows[$key]['quantity'] ?? 0);
        $rows[$key] = ['ingredient_id' => $ingredient->id, 'ingredient_name' => $ingredient->name, 'base_unit' => $ingredient->base_unit, 'quantity' => (string) $current->plus($quantity)->toScale(8, RoundingMode::HalfUp), 'unit_cost' => $ingredient->currentPrice?->base_unit_cost, 'origin' => $origin, 'preparation' => $preparation];
    }
}
