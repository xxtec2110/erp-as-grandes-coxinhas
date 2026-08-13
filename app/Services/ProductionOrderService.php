<?php

namespace App\Services;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\StockMovement;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProductionOrderService
{
    public function __construct(private AuthorizationService $authorization, private ProductionRecipeSnapshotService $snapshots, private IngredientStockService $ingredientStock, private StockMovementService $productStock, private StockBalanceService $productBalances) {}

    public function plan(array $data, User $user, string $source = 'web'): ProductionOrder
    {
        $this->authorization->authorize($user, 'production.orders.create', (int) $data['location_id']);

        return DB::transaction(function () use ($data, $user, $source) {
            $existing = ProductionOrder::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing->load('items.product');
            }
            $location = Location::query()->findOrFail($data['location_id']);
            if ($location->type !== Location::TYPE_PRODUCTION) {
                throw new DomainException('A ordem exige uma unidade de produção.');
            }
            $order = ProductionOrder::query()->create(['location_id' => $location->id, 'production_date' => $data['production_date'], 'status' => 'planned', 'source' => $source, 'idempotency_key' => $data['idempotency_key'], 'created_by' => $user->id, 'notes' => $data['notes'] ?? null]);
            foreach ($data['items'] as $row) {
                $product = Product::query()->findOrFail($row['product_id']);
                $snapshot = $this->snapshots->capture($product);
                $order->items()->create(['product_id' => $product->id, 'planned_quantity' => (string) BigDecimal::of($row['planned_quantity'])->toScale(6, RoundingMode::Unnecessary), 'recipe_snapshot' => $snapshot, 'unit_cost_snapshot' => $snapshot['unit_cost'], 'status' => 'planned']);
            }
            $this->audit($order, $user, 'planned', $source, ['items' => $order->items()->count()]);

            return $order->load('items.product');
        });
    }

    public function planAndComplete(array $data, User $user, string $source = 'agent'): ProductionOrder
    {
        return DB::transaction(function () use ($data, $user, $source) {
            $planItems = array_map(fn ($item) => ['product_id' => $item['product_id'], 'planned_quantity' => $item['produced_quantity']], $data['items']);
            $order = $this->plan([...$data, 'items' => $planItems], $user, $source);
            $quantities = [];
            foreach ($order->items as $index => $item) {
                $quantities[$item->id] = $data['items'][$index]['produced_quantity'];
            }

            return $this->complete($order, $quantities, $user, $source);
        });
    }

    public function complete(ProductionOrder $order, array $quantities, User $user, string $source = 'web'): ProductionOrder
    {
        $this->authorization->authorize($user, 'production.orders.complete', $order->location_id);

        return DB::transaction(function () use ($order, $quantities, $user, $source) {
            $order = ProductionOrder::query()->with('items.product')->lockForUpdate()->findOrFail($order->id);
            if ($order->status === 'completed') {
                return $order;
            } if ($order->status !== 'planned') {
                throw new DomainException('Somente uma ordem planejada pode ser concluída.');
            }
            Location::query()->whereKey($order->location_id)->lockForUpdate()->firstOrFail();
            $requirements = [];
            foreach ($order->items as $item) {
                $produced = BigDecimal::of($quantities[$item->id] ?? $item->planned_quantity)->toScale(6, RoundingMode::Unnecessary);
                if (! $produced->isPositive()) {
                    throw new DomainException('A quantidade produzida deve ser maior que zero.');
                }foreach ($item->recipe_snapshot['consumption_per_product'] as $row) {
                    $id = (int) $row['ingredient_id'];
                    $requirements[$id] = BigDecimal::of($requirements[$id] ?? 0)->plus(BigDecimal::of($row['quantity'])->multipliedBy($produced));
                }
            }
            foreach ($requirements as $ingredientId => $required) {
                $available = BigDecimal::of($this->ingredientStock->balance($ingredientId, $order->location_id));
                if ($available->isLessThan($required)) {
                    $ingredient = Ingredient::query()->findOrFail($ingredientId);
                    $missing = $required->minus($available);
                    throw new DomainException("Estoque insuficiente — {$ingredient->name}. Necessário: {$required}; disponível: {$available}; falta: {$missing} {$ingredient->base_unit}.");
                }
            }
            foreach ($order->items as $item) {
                $produced = BigDecimal::of($quantities[$item->id] ?? $item->planned_quantity)->toScale(6);
                foreach ($item->recipe_snapshot['consumption_per_product'] as $row) {
                    $quantity = BigDecimal::of($row['quantity'])->multipliedBy($produced)->toScale(6, RoundingMode::HalfUp);
                    $this->ingredientStock->record(['ingredient_id' => $row['ingredient_id'], 'location_id' => $order->location_id, 'type' => 'production_consumption', 'quantity_delta' => (string) $quantity->negated(), 'operation_date' => $order->production_date->toDateString(), 'reference_type' => ProductionOrderItem::class, 'reference_id' => $item->id, 'idempotency_key' => "production-order:{$order->id}:item:{$item->id}:ingredient:{$row['ingredient_id']}:consumption", 'created_by' => $user->id, 'source' => $source, 'unit_cost_snapshot' => $row['unit_cost'], 'metadata' => ['consumption_source' => 'recipe_theoretical', 'recipe_snapshot_version' => $item->recipe_snapshot['version']]]);
                }
                $this->productStock->record(new RecordStockMovementData($item->product_id, $order->location_id, StockMovementType::Production, (string) $produced, $order->production_date->toDateString(), "production-order:{$order->id}:item:{$item->id}:product", $user->id, "Ordem de produção #{$order->id}.", ProductionOrderItem::class, (string) $item->id));
                $item->update(['produced_quantity' => (string) $produced, 'total_cost_snapshot' => (string) BigDecimal::of($item->unit_cost_snapshot)->multipliedBy($produced)->toScale(8, RoundingMode::HalfUp), 'status' => 'completed']);
            }
            $order->update(['status' => 'completed', 'completed_by' => $user->id, 'completed_at' => now()]);
            $this->audit($order, $user, 'completed', $source);

            return $order->refresh()->load('items.product');
        });
    }

    public function reverse(ProductionOrder $order, string $reason, User $user, string $source = 'web'): ProductionOrder
    {
        $this->authorization->authorize($user, 'production.orders.reverse', $order->location_id);

        return DB::transaction(function () use ($order, $reason, $user, $source) {
            $order = ProductionOrder::query()->with('items.product')->lockForUpdate()->findOrFail($order->id);
            if ($order->status === 'reversed') {
                return $order;
            }if ($order->status !== 'completed') {
                throw new DomainException('Somente uma ordem concluída pode ser revertida.');
            }
            foreach ($order->items as $item) {
                if (BigDecimal::of($this->productBalances->balance($item->product_id, $order->location_id))->isLessThan($item->produced_quantity)) {
                    throw new DomainException("Saldo insuficiente de {$item->product->name} para reverter a ordem.");
                }
            }
            foreach ($order->items as $item) {
                $original = StockMovement::query()->where('idempotency_key', "production-order:{$order->id}:item:{$item->id}:product")->firstOrFail();
                $this->productStock->record(new RecordStockMovementData($item->product_id, $order->location_id, StockMovementType::Reversal, (string) BigDecimal::of($item->produced_quantity)->negated(), now()->toDateString(), "production-order:{$order->id}:item:{$item->id}:product:reversal", $user->id, $reason, ProductionOrderItem::class, (string) $item->id, $original->id));
                foreach (IngredientStockMovement::query()->where('reference_type', ProductionOrderItem::class)->where('reference_id', $item->id)->where('type', 'production_consumption')->get() as $movement) {
                    $this->ingredientStock->record(['ingredient_id' => $movement->ingredient_id, 'location_id' => $movement->location_id, 'type' => 'reversal', 'quantity_delta' => (string) BigDecimal::of($movement->quantity_delta)->negated(), 'operation_date' => now()->toDateString(), 'reference_type' => ProductionOrderItem::class, 'reference_id' => $item->id, 'idempotency_key' => $movement->idempotency_key.':reversal', 'created_by' => $user->id, 'source' => $source, 'unit_cost_snapshot' => $movement->unit_cost_snapshot, 'metadata' => ['reason' => $reason], 'reversal_of_id' => $movement->id]);
                }
            }
            $order->update(['status' => 'reversed', 'reversed_by' => $user->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);
            $this->audit($order, $user, 'reversed', $source, ['reason' => $reason]);

            return $order->refresh();
        });
    }

    private function audit(ProductionOrder $order, User $user, string $event, string $source, array $payload = []): void
    {
        $order->audits()->create(['user_id' => $user->id, 'event' => $event, 'source' => $source, 'payload' => $payload]);
    }
}
