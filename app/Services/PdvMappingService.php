<?php

namespace App\Services;

use App\Models\Acquirer;
use App\Models\CardBrand;
use App\Models\PdvConnection;
use App\Models\PdvPaymentMethodMapping;
use App\Models\PdvProductMapping;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PdvMappingService
{
    public function __construct(private PdvPaymentCompatibilityService $payments) {}

    public function confirmProduct(PdvConnection $connection, string $externalProductId, int $productId, bool $confirmRemap = false): PdvProductMapping
    {
        return DB::transaction(fn (): PdvProductMapping => $this->confirmProductInTransaction($connection, $externalProductId, $productId, $confirmRemap));
    }

    /** @param array<int, array{external_product_id:string,product_id:int,confirm_remap?:bool}> $rows @return array<int, PdvProductMapping> */
    public function confirmProducts(PdvConnection $connection, array $rows): array
    {
        return DB::transaction(fn (): array => collect($rows)
            ->map(fn (array $row): PdvProductMapping => $this->confirmProductInTransaction(
                $connection,
                $row['external_product_id'],
                $row['product_id'],
                (bool) ($row['confirm_remap'] ?? false),
            ))
            ->all());
    }

    public function confirmPayment(PdvConnection $connection, string $externalFormId, array $data): PdvPaymentMethodMapping
    {
        return DB::transaction(function () use ($connection, $externalFormId, $data): PdvPaymentMethodMapping {
            $external = $this->externalPayment($connection, $externalFormId);
            $compatibility = $this->payments->forExternal($external->external_form_description, $external->external_type);
            if (! $compatibility['supported'] || $compatibility['method'] !== $data['payment_method']) {
                throw ValidationException::withMessages(['payment_method' => $compatibility['reason'] ?? 'A equivalência escolhida não corresponde à forma externa.']);
            }

            $acquirerId = $data['payment_method'] === 'cash' ? null : (int) $data['acquirer_id'];
            $cardBrandId = $data['payment_method'] === 'cash' ? null : (int) $data['card_brand_id'];
            if ($data['payment_method'] !== 'cash') {
                $this->activeAcquirer($acquirerId);
                $this->activeCardBrand($cardBrandId);
            }

            $mapping = PdvPaymentMethodMapping::query()
                ->where('pdv_connection_id', $connection->id)
                ->where('external_method_code', $externalFormId)
                ->lockForUpdate()
                ->first();
            $newTarget = [$data['payment_method'], $acquirerId, $cardBrandId];
            $oldTarget = [$mapping?->payment_method, $mapping?->acquirer_id, $mapping?->card_brand_id];
            if ($mapping?->status === 'confirmed' && $newTarget !== $oldTarget && ! ($data['confirm_remap'] ?? false)) {
                throw ValidationException::withMessages(['confirm_remap' => 'A alteração de um mapping financeiro confirmado exige confirmação explícita.']);
            }
            if ($mapping?->status === 'confirmed' && $newTarget === $oldTarget) {
                return $mapping;
            }

            $mapping ??= new PdvPaymentMethodMapping([
                'pdv_connection_id' => $connection->id,
                'external_method_code' => $externalFormId,
            ]);
            $mapping->fill([
                'external_name' => $external->external_form_description,
                'payment_method' => $data['payment_method'],
                'acquirer_id' => $acquirerId,
                'card_brand_id' => $cardBrandId,
                'status' => 'confirmed',
            ])->save();

            return $mapping->refresh();
        });
    }

    private function confirmProductInTransaction(PdvConnection $connection, string $externalProductId, int $productId, bool $confirmRemap): PdvProductMapping
    {
        $external = $this->externalProduct($connection, $externalProductId);
        $product = Product::query()->whereKey($productId)->where('active', true)->lockForUpdate()->first();
        if ($product === null) {
            throw ValidationException::withMessages(['product_id' => 'Selecione um produto ERP existente e ativo.']);
        }

        $mapping = PdvProductMapping::query()
            ->where('pdv_connection_id', $connection->id)
            ->where('external_product_id', $externalProductId)
            ->lockForUpdate()
            ->first();
        if ($mapping?->status === 'confirmed' && $mapping->product_id !== $product->id && ! $confirmRemap) {
            throw ValidationException::withMessages(['confirm_remap' => 'A alteração de um mapping confirmado exige confirmação explícita.']);
        }
        if ($mapping?->status === 'confirmed' && $mapping->product_id === $product->id) {
            return $mapping;
        }

        $mapping ??= new PdvProductMapping([
            'pdv_connection_id' => $connection->id,
            'external_product_id' => $externalProductId,
        ]);
        $mapping->fill([
            'external_sku' => $external->external_product_code,
            'external_name' => $external->description,
            'product_id' => $product->id,
            'status' => 'confirmed',
            'match_source' => 'admin',
            'confidence' => null,
        ])->save();

        return $mapping->refresh();
    }

    private function externalProduct(PdvConnection $connection, string $externalProductId): object
    {
        $row = DB::table('pdv_order_items as items')
            ->join('pdv_orders as orders', 'orders.id', '=', 'items.pdv_order_id')
            ->where('orders.pdv_connection_id', $connection->id)
            ->where('items.external_product_id', $externalProductId)
            ->where('items.present_in_latest', true)
            ->selectRaw('items.external_product_id, MAX(items.external_product_code) AS external_product_code, MAX(items.description) AS description')
            ->groupBy('items.external_product_id')
            ->first();
        if ($row === null) {
            throw ValidationException::withMessages(['external_product_id' => 'O produto externo não pertence ao staging desta conexão.']);
        }

        return $row;
    }

    private function externalPayment(PdvConnection $connection, string $externalFormId): object
    {
        $row = DB::table('pdv_order_payments as payments')
            ->join('pdv_orders as orders', 'orders.id', '=', 'payments.pdv_order_id')
            ->where('orders.pdv_connection_id', $connection->id)
            ->where('payments.external_form_id', $externalFormId)
            ->where('payments.present_in_latest', true)
            ->selectRaw('payments.external_form_id, MAX(payments.external_form_description) AS external_form_description, MAX(payments.external_type) AS external_type')
            ->groupBy('payments.external_form_id')
            ->first();
        if ($row === null) {
            throw ValidationException::withMessages(['external_form_id' => 'A forma de pagamento externa não pertence ao staging desta conexão.']);
        }

        return $row;
    }

    private function activeAcquirer(int $id): void
    {
        if (! Acquirer::query()->whereKey($id)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['acquirer_id' => 'Selecione uma adquirente ativa.']);
        }
    }

    private function activeCardBrand(int $id): void
    {
        if (! CardBrand::query()->whereKey($id)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['card_brand_id' => 'Selecione uma bandeira ativa.']);
        }
    }
}
