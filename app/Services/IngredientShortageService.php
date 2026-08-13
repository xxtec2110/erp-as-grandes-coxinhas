<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Location;
use App\Models\ProductionOrderItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class IngredientShortageService
{
    public function __construct(private IngredientStockService $stock) {}

    public function forLocation(Location $location): array
    {
        $required = [];
        $items = ProductionOrderItem::query()->whereHas('order', fn ($q) => $q->where('location_id', $location->id)->where('status', 'planned'))->get();
        foreach ($items as $item) {
            foreach ($item->recipe_snapshot['consumption_per_product'] ?? [] as $row) {
                $id = (int) $row['ingredient_id'];
                $required[$id] = BigDecimal::of($required[$id] ?? 0)->plus(BigDecimal::of($row['quantity'])->multipliedBy($item->planned_quantity));
            }
        }

        $result = [];
        foreach ($required as $id => $need) {
            $available = BigDecimal::of($this->stock->balance($id, $location->id));
            if ($available->isLessThan($need)) {
                $ingredient = Ingredient::query()->findOrFail($id);
                $result[] = ['ingredient' => $ingredient, 'available' => (string) $available->toScale(6), 'required' => (string) $need->toScale(6, RoundingMode::HalfUp), 'missing' => (string) $need->minus($available)->toScale(6, RoundingMode::HalfUp)];
            }
        }

        return $result;
    }
}
