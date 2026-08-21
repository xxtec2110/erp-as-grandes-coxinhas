<?php

namespace App\Services;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\ProductSalePaymentMethod;
use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\StockMovement;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProductSaleService
{
    public function __construct(private StockMovementService $movements, private StockBalanceService $balances, private AuthorizationService $authorization, private PaymentFeeResolver $feeResolver, private PaymentFeeCalculator $feeCalculator, private ProductCostSnapshotService $costSnapshots) {}

    /** @param array<string, mixed> $data */
    public function record(array $data, User $user, string $source = 'web'): ProductSale
    {
        $this->authorization->authorize($user, 'sales.create', (int) $data['location_id']);

        return $this->persist($data, $user, $source, false);
    }

    /** @param array<string, mixed> $data */
    public function recordPdvItem(array $data, User $user): ProductSale
    {
        $this->authorization->authorize($user, 'pdv.manage', (int) $data['location_id']);

        return $this->persist($data, $user, 'pdv_order', true);
    }

    /** @param array<string, mixed> $data */
    private function persist(array $data, User $user, string $source, bool $pdvOrderItem): ProductSale
    {
        return DB::transaction(function () use ($data, $user, $source, $pdvOrderItem): ProductSale {
            $quantity = BigDecimal::of($data['quantity'])->toScale(6, RoundingMode::Unnecessary);
            $price = BigDecimal::of($data['unit_price'])->toScale(4, RoundingMode::Unnecessary);
            $subtotal = $pdvOrderItem
                ? BigDecimal::of($data['subtotal_amount'])->toScale(2, RoundingMode::Unnecessary)
                : $quantity->multipliedBy($price)->toScale(2, RoundingMode::HalfUp);
            $discount = $pdvOrderItem
                ? BigDecimal::of($data['discount_amount'])->toScale(2, RoundingMode::Unnecessary)
                : BigDecimal::zero()->toScale(2);
            $total = $pdvOrderItem
                ? BigDecimal::of($data['total_amount'])->toScale(2, RoundingMode::Unnecessary)
                : $subtotal;
            $method = $pdvOrderItem ? null : ProductSalePaymentMethod::from($data['payment_method'] ?? ProductSalePaymentMethod::Cash->value);
            $methodValue = $pdvOrderItem ? (string) $data['payment_method_snapshot'] : $method->value;
            $requiresCard = $method?->requiresCardConfiguration() ?? false;
            $fee = $requiresCard
                ? $this->feeResolver->resolve((int) $data['acquirer_id'], (int) $data['card_brand_id'], $methodValue, isset($data['installments']) ? (int) $data['installments'] : null, $data['operation_date'])
                : null;
            if ($requiresCard && $fee === null) {
                throw new DomainException('Nenhuma taxa vigente foi encontrada para esta forma de pagamento.');
            }
            $percentage = $pdvOrderItem ? '0' : ($fee?->fee_percentage ?? '0');
            $fixedFee = $pdvOrderItem ? '0' : ($fee?->fixed_fee ?? '0');
            $financial = $pdvOrderItem
                ? [
                    'fee_amount' => (string) BigDecimal::of($data['fee_amount_snapshot'])->toScale(2, RoundingMode::Unnecessary),
                    'net_amount' => (string) BigDecimal::of($data['net_amount_snapshot'])->toScale(2, RoundingMode::Unnecessary),
                ]
                : $this->feeCalculator->calculate((string) $total, $percentage, $fixedFee);
            $existing = ProductSale::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                $same = $existing->product_id === (int) $data['product_id']
                    && $existing->location_id === (int) $data['location_id']
                    && $existing->quantity === (string) $quantity
                    && $existing->unit_price === (string) $price
                    && $existing->total_amount === (string) $total
                    && $existing->operation_date->toDateString() === $data['operation_date']
                    && $existing->payment_method === $methodValue
                    && $existing->product_sale_order_id === ($data['product_sale_order_id'] ?? null);
                if (! $same) {
                    throw new DomainException('A chave idempotente já foi usada por outra venda.');
                }

                return $existing;
            }

            $location = Location::query()->whereKey($data['location_id'])->lockForUpdate()->firstOrFail();
            if ($location->type !== Location::TYPE_STORE) {
                throw new DomainException('Vendas devem ser registradas em uma unidade do tipo loja.');
            }
            if (BigDecimal::of($this->balances->balance((int) $data['product_id'], (int) $data['location_id']))->isLessThan($quantity)) {
                throw new DomainException('Estoque insuficiente para registrar a venda.');
            }

            $product = Product::query()->with(['recipe', 'currentPrice'])->findOrFail($data['product_id']);
            $operationDate = Carbon::parse($data['operation_date']);
            $costSnapshot = $operationDate->greaterThanOrEqualTo(today())
                ? $this->costSnapshots->current($product, 'sale', (string) $data['idempotency_key'], ['operation_date' => $data['operation_date']])
                : $this->costSnapshots->at($product, $operationDate->endOfDay());
            $totalCost = $costSnapshot === null ? null : BigDecimal::of($costSnapshot->unit_cost)->multipliedBy($quantity)->toScale(2, RoundingMode::HalfUp);
            $grossProfit = $totalCost === null ? null : $total->minus($totalCost)->toScale(2, RoundingMode::HalfUp);
            $grossMargin = $grossProfit === null || $total->isZero() ? null : $grossProfit->multipliedBy(100)->dividedBy($total, 4, RoundingMode::HalfUp);

            $sale = ProductSale::query()->create([
                'product_id' => $data['product_id'],
                'location_id' => $data['location_id'],
                'product_sale_order_id' => $data['product_sale_order_id'] ?? null,
                'acquirer_id' => $requiresCard ? $data['acquirer_id'] : null,
                'card_brand_id' => $requiresCard ? $data['card_brand_id'] : null,
                'payment_method' => $methodValue,
                'installments' => $requiresCard ? ($data['installments'] ?? null) : null,
                'quantity' => (string) $quantity,
                'unit_price' => (string) $price,
                'total_amount' => (string) $total,
                'subtotal_amount_snapshot' => (string) $subtotal,
                'discount_amount_snapshot' => (string) $discount,
                'gross_amount' => (string) $total,
                'fee_percentage_snapshot' => $percentage,
                'fixed_fee_snapshot' => $fixedFee,
                'fee_amount_snapshot' => $financial['fee_amount'],
                'net_amount' => $financial['net_amount'],
                'payment_fee_id' => $fee?->id,
                'product_cost_snapshot_id' => $costSnapshot?->id,
                'unit_cost_snapshot' => $costSnapshot?->unit_cost,
                'total_cost_snapshot' => $totalCost,
                'gross_profit_snapshot' => $grossProfit,
                'gross_margin_percentage_snapshot' => $grossMargin,
                'operation_date' => $data['operation_date'],
                'source' => $source,
                'pdv_connection_id' => $data['pdv_connection_id'] ?? null,
                'pdv_order_item_id' => $data['pdv_order_item_id'] ?? null,
                'external_sale_id' => $data['external_sale_id'] ?? null,
                'external_item_id' => $data['external_item_id'] ?? null,
                'external_status' => $data['external_status'] ?? null,
                'external_updated_at' => $data['external_updated_at'] ?? null,
                'idempotency_key' => $data['idempotency_key'],
                'created_by' => $user->id,
                'notes' => $data['notes'] ?? null,
            ]);
            $this->movements->record(new RecordStockMovementData(
                productId: $sale->product_id,
                locationId: $sale->location_id,
                type: StockMovementType::Sale,
                quantityDelta: (string) $quantity->negated(),
                operationDate: $sale->operation_date->toDateString(),
                idempotencyKey: "sale:{$sale->id}:recorded",
                createdBy: $user->id,
                notes: "Venda #{$sale->id}",
                referenceType: ProductSale::class,
                referenceId: (string) $sale->id,
            ));

            return $sale->load(['product.category', 'location', 'creator']);
        });
    }

    public function reverse(ProductSale $sale, User $user, string $reason): ProductSale
    {
        $this->authorization->authorize($user, 'sales.create', $sale->location_id);

        return $this->reverseAuthorized($sale, $user, $reason);
    }

    public function reversePdvItem(ProductSale $sale, User $user, string $reason): ProductSale
    {
        $this->authorization->authorize($user, 'pdv.manage', $sale->location_id);

        return $this->reverseAuthorized($sale, $user, $reason);
    }

    private function reverseAuthorized(ProductSale $sale, User $user, string $reason): ProductSale
    {
        return DB::transaction(function () use ($sale, $user, $reason): ProductSale {
            $sale = ProductSale::query()->lockForUpdate()->findOrFail($sale->id);
            if ($sale->cancelled_at !== null) {
                return $sale;
            }
            $original = StockMovement::query()->where('idempotency_key', "sale:{$sale->id}:recorded")->firstOrFail();
            $this->movements->record(new RecordStockMovementData(
                productId: $sale->product_id,
                locationId: $sale->location_id,
                type: StockMovementType::Reversal,
                quantityDelta: $sale->quantity,
                operationDate: now()->toDateString(),
                idempotencyKey: "sale:{$sale->id}:cancelled",
                createdBy: $user->id,
                notes: $reason,
                referenceType: ProductSale::class,
                referenceId: (string) $sale->id,
                reversalOfId: $original->id,
            ));
            $sale->update(['external_status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancellation_reason' => $reason]);

            return $sale->refresh();
        });
    }
}
