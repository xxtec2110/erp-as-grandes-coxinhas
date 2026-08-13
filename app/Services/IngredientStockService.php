<?php

namespace App\Services;

use App\Models\IngredientStockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class IngredientStockService
{
    public function balance(int $ingredientId, int $locationId): string
    {
        return (string) BigDecimal::of(IngredientStockMovement::query()->where('ingredient_id', $ingredientId)->where('location_id', $locationId)->sum('quantity_delta'))->toScale(6);
    }

    public function record(array $data): IngredientStockMovement
    {
        $quantity = BigDecimal::of($data['quantity_delta'])->toScale(6, RoundingMode::Unnecessary);
        if ($quantity->isZero()) {
            throw new DomainException('O movimento de insumo não pode ter quantidade zero.');
        }
        $existing = IngredientStockMovement::query()->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing !== null) {
            if ($existing->ingredient_id !== (int) $data['ingredient_id'] || $existing->location_id !== (int) $data['location_id'] || $existing->quantity_delta !== (string) $quantity) {
                throw new DomainException('A chave idempotente já foi usada por outro movimento de insumo.');
            }

            return $existing;
        }

        return IngredientStockMovement::query()->create([...$data, 'quantity_delta' => (string) $quantity]);
    }
}
