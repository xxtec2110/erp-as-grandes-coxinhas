<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;

class IngredientStockOperationService
{
    public function __construct(private AuthorizationService $auth, private UnitConversionService $units, private IngredientStockService $stock) {}

    public function record(array $data, User $user, string $kind): mixed
    {
        $permission = $kind === 'loss' ? 'ingredient_losses.create' : 'ingredient_stock.adjust';
        $this->auth->authorize($user, $permission, (int) $data['location_id']);
        $ingredient = Ingredient::query()->findOrFail($data['ingredient_id']);
        $quantity = BigDecimal::of($this->units->normalize($data['quantity'], $data['unit'], $ingredient->base_unit));
        $negative = $kind === 'loss' || ($data['direction'] ?? null) === 'negative';
        if ($negative && BigDecimal::of($this->stock->balance($ingredient->id, $data['location_id']))->isLessThan($quantity)) {
            throw new DomainException('Estoque de insumo insuficiente para esta baixa.');
        }

        return $this->stock->record(['ingredient_id' => $ingredient->id, 'location_id' => $data['location_id'], 'type' => $kind === 'loss' ? 'loss' : (($data['direction'] === 'positive') ? 'positive_adjustment' : 'negative_adjustment'), 'quantity_delta' => (string) ($negative ? $quantity->negated() : $quantity), 'operation_date' => $data['operation_date'], 'idempotency_key' => $data['idempotency_key'], 'created_by' => $user->id, 'source' => 'web', 'notes' => $data['reason'], 'metadata' => ['reason' => $data['reason']]]);
    }
}
