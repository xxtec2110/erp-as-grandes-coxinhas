<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use App\Models\Location;

class IngredientStockPositionService
{
    public function forLocation(Location $location, array $filters = []): mixed
    {
        return Ingredient::query()->with(['category', 'currentPrice'])
            ->when($filters['ingredient_id'] ?? null, fn ($q, $v) => $q->whereKey($v))
            ->when($filters['ingredient'] ?? null, fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('ingredient_category_id', $v))
            ->orderBy('name')->get()
            ->map(fn ($ingredient) => ['ingredient' => $ingredient, 'balance' => (string) app(IngredientStockService::class)->balance($ingredient->id, $location->id), 'last_movement' => IngredientStockMovement::query()->where('ingredient_id', $ingredient->id)->where('location_id', $location->id)->latest('operation_date')->latest('id')->first()]);
    }
}
