<?php

namespace App\Services;

use App\Models\ProductStockPolicy;
use App\Models\ProductStockPolicyHistory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProductStockPolicyService
{
    /** @param array<string, mixed> $data */
    public function save(array $data, ?int $userId, string $channel = 'web'): ProductStockPolicy
    {
        return DB::transaction(function () use ($data, $userId, $channel): ProductStockPolicy {
            $minimum = isset($data['minimum_quantity']) && $data['minimum_quantity'] !== ''
                ? BigDecimal::of($data['minimum_quantity'])->toScale(6, RoundingMode::Unnecessary)
                : null;
            $target = BigDecimal::of($data['target_quantity'])->toScale(6, RoundingMode::Unnecessary);
            $history = ProductStockPolicyHistory::query()
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if ($history !== null) {
                $samePayload = $history->policy->product_id === (int) $data['product_id']
                    && $history->policy->location_id === (int) $data['location_id']
                    && $history->new_minimum_quantity === ($minimum !== null ? (string) $minimum : null)
                    && $history->new_target_quantity === (string) $target
                    && $history->new_production_priority === (int) $data['production_priority']
                    && $history->new_active === (bool) $data['active'];

                if (! $samePayload) {
                    throw new DomainException('A chave idempotente já foi usada por outra alteração de política.');
                }

                return $history->policy;
            }

            if ($minimum !== null && $minimum->isGreaterThan($target)) {
                throw new DomainException('O estoque mínimo não pode ser maior que o estoque-alvo.');
            }

            $policy = ProductStockPolicy::query()
                ->where('product_id', $data['product_id'])
                ->where('location_id', $data['location_id'])
                ->lockForUpdate()
                ->first();
            $previous = $policy?->only([
                'minimum_quantity',
                'target_quantity',
                'production_priority',
                'active',
            ]);

            $values = [
                'minimum_quantity' => $minimum !== null ? (string) $minimum : null,
                'target_quantity' => (string) $target,
                'production_priority' => (int) $data['production_priority'],
                'active' => (bool) $data['active'],
                'updated_by' => $userId,
            ];

            if ($policy === null) {
                $policy = ProductStockPolicy::query()->create([
                    'product_id' => $data['product_id'],
                    'location_id' => $data['location_id'],
                    ...$values,
                ]);
            } else {
                $policy->update($values);
            }

            $policy->histories()->create([
                'previous_minimum_quantity' => $previous['minimum_quantity'] ?? null,
                'new_minimum_quantity' => $values['minimum_quantity'],
                'previous_target_quantity' => $previous['target_quantity'] ?? null,
                'new_target_quantity' => $values['target_quantity'],
                'previous_production_priority' => $previous['production_priority'] ?? null,
                'new_production_priority' => $values['production_priority'],
                'previous_active' => $previous['active'] ?? null,
                'new_active' => $values['active'],
                'channel' => $channel,
                'idempotency_key' => $data['idempotency_key'],
                'changed_by' => $userId,
            ]);

            return $policy->refresh();
        });
    }
}
