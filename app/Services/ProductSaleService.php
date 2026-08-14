<?php

namespace App\Services;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\ProductSale;
use App\Models\StockMovement;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProductSaleService
{
    public function __construct(private StockMovementService $movements, private StockBalanceService $balances, private AuthorizationService $authorization, private PaymentFeeResolver $feeResolver, private PaymentFeeCalculator $feeCalculator) {}

    /** @param array<string, mixed> $data */
    public function record(array $data, User $user, string $source = 'web'): ProductSale
    {
        $this->authorization->authorize($user, 'sales.create', (int) $data['location_id']);

        return DB::transaction(function () use ($data, $user, $source): ProductSale {
            $quantity = BigDecimal::of($data['quantity'])->toScale(6, RoundingMode::Unnecessary);
            $price = BigDecimal::of($data['unit_price'])->toScale(4, RoundingMode::Unnecessary);
            $total = $quantity->multipliedBy($price)->toScale(2, RoundingMode::HalfUp);
            $method = $data['payment_method'] ?? 'cash';
            $fee = $method === 'cash' ? null : $this->feeResolver->resolve((int) $data['acquirer_id'], (int) $data['card_brand_id'], $method, isset($data['installments']) ? (int) $data['installments'] : null, $data['operation_date']);
            if ($method !== 'cash' && $fee === null) {
                throw new DomainException('Nenhuma taxa vigente foi encontrada para esta forma de pagamento.');
            }
            $percentage = $fee?->fee_percentage ?? '0';
            $fixedFee = $fee?->fixed_fee ?? '0';
            $financial = $this->feeCalculator->calculate((string) $total, $percentage, $fixedFee);
            $existing = ProductSale::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                $same = $existing->product_id === (int) $data['product_id'] && $existing->location_id === (int) $data['location_id'] && $existing->quantity === (string) $quantity && $existing->unit_price === (string) $price && $existing->operation_date->toDateString() === $data['operation_date'] && $existing->payment_method === $method;
                if (! $same) {
                    throw new DomainException('A chave idempotente já foi usada por outra venda.');
                }

                return $existing;
            }
            Location::query()->whereKey($data['location_id'])->lockForUpdate()->firstOrFail();
            if (BigDecimal::of($this->balances->balance((int) $data['product_id'], (int) $data['location_id']))->isLessThan($quantity)) {
                throw new DomainException('Estoque insuficiente para registrar a venda.');
            }
            $sale = ProductSale::query()->create(['product_id' => $data['product_id'], 'location_id' => $data['location_id'], 'acquirer_id' => $data['acquirer_id'] ?? null, 'card_brand_id' => $data['card_brand_id'] ?? null, 'payment_method' => $method, 'installments' => $data['installments'] ?? null, 'quantity' => (string) $quantity, 'unit_price' => (string) $price, 'total_amount' => (string) $total, 'gross_amount' => (string) $total, 'fee_percentage_snapshot' => $percentage, 'fixed_fee_snapshot' => $fixedFee, 'fee_amount_snapshot' => $financial['fee_amount'], 'net_amount' => $financial['net_amount'], 'payment_fee_id' => $fee?->id, 'operation_date' => $data['operation_date'], 'source' => $source, 'pdv_connection_id' => $data['pdv_connection_id'] ?? null, 'external_sale_id' => $data['external_sale_id'] ?? null, 'external_item_id' => $data['external_item_id'] ?? null, 'external_status' => $data['external_status'] ?? null, 'external_updated_at' => $data['external_updated_at'] ?? null, 'idempotency_key' => $data['idempotency_key'], 'created_by' => $user->id, 'notes' => $data['notes'] ?? null]);
            $this->movements->record(new RecordStockMovementData(productId: $sale->product_id, locationId: $sale->location_id, type: StockMovementType::Sale, quantityDelta: (string) $quantity->negated(), operationDate: $sale->operation_date->toDateString(), idempotencyKey: "sale:{$sale->id}:recorded", createdBy: $user->id, notes: "Venda #{$sale->id}", referenceType: ProductSale::class, referenceId: (string) $sale->id));

            return $sale->load(['product.category', 'location', 'creator']);
        });
    }

    public function reverse(ProductSale $sale, User $user, string $reason): ProductSale
    {
        $this->authorization->authorize($user, 'sales.create', $sale->location_id);

        return DB::transaction(function () use ($sale, $user, $reason): ProductSale {
            $sale = ProductSale::query()->lockForUpdate()->findOrFail($sale->id);
            if ($sale->cancelled_at !== null) {
                return $sale;
            }
            $original = StockMovement::query()->where('idempotency_key', "sale:{$sale->id}:recorded")->firstOrFail();
            $this->movements->record(new RecordStockMovementData(productId: $sale->product_id, locationId: $sale->location_id, type: StockMovementType::Reversal, quantityDelta: $sale->quantity, operationDate: now()->toDateString(), idempotencyKey: "sale:{$sale->id}:cancelled", createdBy: $user->id, notes: $reason, referenceType: ProductSale::class, referenceId: (string) $sale->id, reversalOfId: $original->id));
            $sale->update(['external_status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancellation_reason' => $reason]);

            return $sale->refresh();
        });
    }
}
