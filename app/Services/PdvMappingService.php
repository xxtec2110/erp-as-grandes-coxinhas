<?php

namespace App\Services;

use App\Enums\ProductSalePaymentMethod;
use App\Models\Acquirer;
use App\Models\CardBrand;
use App\Models\PaymentFee;
use App\Models\PdvConnection;
use App\Models\PdvPaymentMethodMapping;
use App\Models\PdvProductMapping;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PdvMappingService
{
    public function __construct(
        private PdvPaymentCompatibilityService $payments,
        private AuthorizationService $authorization,
        private PdvMappingAuditService $audits,
    ) {}

    public function confirmProduct(PdvConnection $connection, string $externalProductId, int $productId, User $user, string $idempotencyKey, bool $confirmRemap = false, ?string $reason = null): PdvProductMapping
    {
        $this->authorization->authorize($user, 'pdv.manage', (int) $connection->location_id);
        $current = PdvProductMapping::query()->whereBelongsTo($connection, 'connection')->where('external_product_id', $externalProductId)->first();
        if ($current?->status === 'confirmed' && $current->product_id === $productId) {
            return $current;
        }

        $action = $current === null ? 'create' : ($current->status === 'confirmed' ? 'change' : 'confirm');
        $result = $this->audits->execute(
            $user,
            $connection,
            'product',
            $externalProductId,
            $action,
            $idempotencyKey,
            fn (): PdvProductMapping => $this->persistProduct($connection, $externalProductId, $productId, $confirmRemap),
            $current,
            $reason,
        );

        return $result instanceof PdvProductMapping ? $result : throw new \LogicException('A auditoria retornou um mapping de produto inválido.');
    }

    /** @param array<int, array{external_product_id:string,product_id:int,confirm_remap?:bool}> $rows @return array<int, PdvProductMapping> */
    public function confirmProducts(PdvConnection $connection, array $rows, User $user, string $idempotencyKey, ?string $reason = null): array
    {
        return collect($rows)->map(fn (array $row): PdvProductMapping => $this->confirmProduct(
            $connection,
            $row['external_product_id'],
            $row['product_id'],
            $user,
            $idempotencyKey.':'.hash('sha256', $row['external_product_id']),
            (bool) ($row['confirm_remap'] ?? false),
            $reason,
        ))->all();
    }

    /** @param array<string,mixed> $data */
    public function confirmPayment(PdvConnection $connection, string $externalFormId, array $data, User $user): PdvPaymentMethodMapping
    {
        $this->authorization->authorize($user, 'pdv.manage', (int) $connection->location_id);
        $external = $this->externalPayment($connection, $externalFormId);
        $compatibility = $this->payments->forExternal($external->external_form_description, $external->external_type);
        if (! $compatibility['supported'] || $compatibility['method'] !== $data['payment_method']) {
            throw ValidationException::withMessages(['payment_method' => $compatibility['reason'] ?? 'A equivalência escolhida não corresponde à forma externa.']);
        }

        $method = ProductSalePaymentMethod::from($data['payment_method']);
        $acquirerId = $method->requiresCardConfiguration() ? (int) $data['acquirer_id'] : null;
        $cardBrandId = $method->requiresCardConfiguration() && filled($data['card_brand_id'] ?? null) ? (int) $data['card_brand_id'] : null;
        if ($method->requiresCardConfiguration()) {
            $this->activeAcquirer($acquirerId);
            if ($cardBrandId !== null) {
                $this->activeCardBrand($cardBrandId);
            }
            $this->configuredFee($acquirerId, $cardBrandId, $method->value);
        }

        $current = PdvPaymentMethodMapping::query()->whereBelongsTo($connection, 'connection')->where('external_method_code', $externalFormId)->first();
        $newTarget = [$method->value, $acquirerId, $cardBrandId];
        $oldTarget = [$current?->payment_method, $current?->acquirer_id, $current?->card_brand_id];
        if ($current?->status === 'confirmed' && $newTarget === $oldTarget) {
            return $current;
        }

        $action = $current === null ? 'create' : ($current->status === 'confirmed' ? 'change' : 'confirm');
        $result = $this->audits->execute(
            $user,
            $connection,
            'payment',
            $externalFormId,
            $action,
            (string) $data['idempotency_key'],
            fn (): PdvPaymentMethodMapping => $this->persistPayment($connection, $external, $externalFormId, $method->value, $acquirerId, $cardBrandId, (bool) ($data['confirm_remap'] ?? false)),
            $current,
            $data['reason'] ?? null,
        );

        return $result instanceof PdvPaymentMethodMapping ? $result : throw new \LogicException('A auditoria retornou um mapping financeiro inválido.');
    }

    private function persistProduct(PdvConnection $connection, string $externalProductId, int $productId, bool $confirmRemap): PdvProductMapping
    {
        return DB::transaction(function () use ($connection, $externalProductId, $productId, $confirmRemap): PdvProductMapping {
            $external = $this->externalProduct($connection, $externalProductId);
            $product = Product::query()->whereKey($productId)->where('active', true)->lockForUpdate()->first();
            if ($product === null) {
                throw ValidationException::withMessages(['product_id' => 'Selecione um produto ERP existente e ativo.']);
            }

            $mapping = PdvProductMapping::query()->whereBelongsTo($connection, 'connection')->where('external_product_id', $externalProductId)->lockForUpdate()->first();
            if ($mapping?->status === 'confirmed' && $mapping->product_id !== $product->id && ! $confirmRemap) {
                throw ValidationException::withMessages(['confirm_remap' => 'A alteração de um mapping confirmado exige confirmação explícita.']);
            }
            if ($mapping?->status === 'confirmed' && $mapping->product_id === $product->id) {
                return $mapping;
            }

            $mapping ??= new PdvProductMapping(['pdv_connection_id' => $connection->id, 'external_product_id' => $externalProductId]);
            $mapping->fill(['external_sku' => $external->external_product_code, 'external_name' => $external->description, 'product_id' => $product->id, 'status' => 'confirmed', 'match_source' => 'admin', 'confidence' => null])->save();

            return $mapping->refresh();
        });
    }

    private function persistPayment(PdvConnection $connection, object $external, string $externalFormId, string $method, ?int $acquirerId, ?int $cardBrandId, bool $confirmRemap): PdvPaymentMethodMapping
    {
        return DB::transaction(function () use ($connection, $external, $externalFormId, $method, $acquirerId, $cardBrandId, $confirmRemap): PdvPaymentMethodMapping {
            $mapping = PdvPaymentMethodMapping::query()->whereBelongsTo($connection, 'connection')->where('external_method_code', $externalFormId)->lockForUpdate()->first();
            $newTarget = [$method, $acquirerId, $cardBrandId];
            $oldTarget = [$mapping?->payment_method, $mapping?->acquirer_id, $mapping?->card_brand_id];
            if ($mapping?->status === 'confirmed' && $newTarget !== $oldTarget && ! $confirmRemap) {
                throw ValidationException::withMessages(['confirm_remap' => 'A alteração de um mapping financeiro confirmado exige confirmação explícita.']);
            }
            if ($mapping?->status === 'confirmed' && $newTarget === $oldTarget) {
                return $mapping;
            }

            $mapping ??= new PdvPaymentMethodMapping(['pdv_connection_id' => $connection->id, 'external_method_code' => $externalFormId]);
            $mapping->fill(['external_name' => $external->external_form_description, 'payment_method' => $method, 'acquirer_id' => $acquirerId, 'card_brand_id' => $cardBrandId, 'status' => 'confirmed'])->save();

            return $mapping->refresh();
        });
    }

    private function externalProduct(PdvConnection $connection, string $externalProductId): object
    {
        $row = DB::table('pdv_order_items as items')->join('pdv_orders as orders', 'orders.id', '=', 'items.pdv_order_id')
            ->where('orders.pdv_connection_id', $connection->id)->where('items.external_product_id', $externalProductId)->where('items.present_in_latest', true)
            ->selectRaw('items.external_product_id, MAX(items.external_product_code) AS external_product_code, MAX(items.description) AS description')->groupBy('items.external_product_id')->first();
        if ($row === null) {
            throw ValidationException::withMessages(['external_product_id' => 'O produto externo não pertence ao staging desta conexão.']);
        }

        return $row;
    }

    private function externalPayment(PdvConnection $connection, string $externalFormId): object
    {
        $row = DB::table('pdv_order_payments as payments')->join('pdv_orders as orders', 'orders.id', '=', 'payments.pdv_order_id')
            ->where('orders.pdv_connection_id', $connection->id)->where('payments.external_form_id', $externalFormId)->where('payments.present_in_latest', true)
            ->selectRaw('payments.external_form_id, MAX(payments.external_form_description) AS external_form_description, MAX(payments.external_type) AS external_type')->groupBy('payments.external_form_id')->first();
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

    private function configuredFee(int $acquirerId, ?int $cardBrandId, string $method): void
    {
        if (! PaymentFee::query()->where('acquirer_id', $acquirerId)->where('card_brand_id', $cardBrandId)->where('payment_method', $method)->where('active', true)->where('is_current', true)->exists()) {
            throw ValidationException::withMessages(['financial_configuration' => 'Configuração financeira necessária antes de confirmar este mapping.']);
        }
    }
}
