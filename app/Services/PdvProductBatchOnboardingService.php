<?php

namespace App\Services;

use App\Models\PdvConnection;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

class PdvProductBatchOnboardingService
{
    public function __construct(
        private PdvMappingCatalogService $catalog,
        private PdvProductOnboardingService $onboarding,
        private ProductCatalogService $products,
    ) {}

    /** @param array<int,array<string,mixed>> $selectedRows
     * @return array{rows:array<int,array<string,mixed>>,token:string,expires_at:CarbonImmutable}
     */
    public function preview(PdvConnection $connection, User $user, CarbonImmutable $from, CarbonImmutable $to, array $selectedRows): array
    {
        $catalog = $this->catalog->forPeriod($connection, $from, $to, 'all')['products']->keyBy('external_product_id');
        $rows = collect($selectedRows)->map(function (array $selection) use ($connection, $from, $to, $catalog): array {
            $externalId = (string) $selection['external_product_id'];
            $entry = $catalog->get($externalId);
            if ($entry === null || $entry['suggestion']['type'] !== PdvExternalProductSuggestionService::TYPE_NONE) {
                throw ValidationException::withMessages(['rows' => "O produto externo {$externalId} não está mais pendente de cadastro oficial."]);
            }

            $context = $this->onboarding->context($connection, $externalId, $from, $to);
            $category = ProductCategory::query()->whereKey((int) $selection['product_category_id'])->where('active', true)->first();
            if ($category === null) {
                throw ValidationException::withMessages(['rows' => 'A categoria selecionada precisa existir e estar ativa.']);
            }
            $this->onboarding->assertCategoryAllowed($context, $category->id);

            return [
                'external_product_id' => $externalId,
                'external_product_code' => $entry['external_product_code'],
                'external_description' => $entry['description'],
                'name' => trim((string) $selection['name']),
                'product_category_id' => $category->id,
                'category_name' => $category->name,
                'selling_price' => (string) $selection['selling_price'],
                'active' => (bool) ($selection['active'] ?? false),
                'quantity_total' => $entry['quantity_total'],
                'value_total' => $entry['value_total'],
                'order_count' => $entry['order_count'],
                'observed_prices' => $entry['prices']['observed'],
            ];
        })->values()->all();

        $expiresAt = CarbonImmutable::now()->addMinutes(30);
        $payload = [
            'version' => 1,
            'user_id' => $user->id,
            'connection_id' => $connection->id,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'expires_at' => $expiresAt->toIso8601String(),
            'rows' => $rows,
        ];

        return [
            'rows' => $rows,
            'token' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'expires_at' => $expiresAt,
        ];
    }

    /** @return array{created:int,products:array<int,Product>} */
    public function confirm(PdvConnection $connection, User $user, string $token): array
    {
        $payload = $this->decrypt($token);
        if ((int) ($payload['user_id'] ?? 0) !== $user->id || (int) ($payload['connection_id'] ?? 0) !== $connection->id) {
            throw ValidationException::withMessages(['preview_token' => 'A prévia não pertence a este usuário ou conexão.']);
        }
        if (CarbonImmutable::parse((string) ($payload['expires_at'] ?? '1970-01-01'))->isPast()) {
            throw ValidationException::withMessages(['preview_token' => 'A prévia expirou. Gere uma nova conferência.']);
        }

        $from = CarbonImmutable::parse((string) $payload['from'], config('app.timezone'));
        $to = CarbonImmutable::parse((string) $payload['to'], config('app.timezone'));
        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        if ($rows === []) {
            throw ValidationException::withMessages(['preview_token' => 'A prévia não contém produtos.']);
        }

        $created = DB::transaction(function () use ($connection, $user, $from, $to, $rows): array {
            $catalog = $this->catalog->forPeriod($connection, $from, $to, 'all')['products']->keyBy('external_product_id');

            return collect($rows)->map(function (array $row) use ($connection, $user, $from, $to, $catalog): Product {
                $externalId = (string) $row['external_product_id'];
                $entry = $catalog->get($externalId);
                if ($entry === null || $entry['suggestion']['type'] !== PdvExternalProductSuggestionService::TYPE_NONE) {
                    throw ValidationException::withMessages(['preview_token' => "O produto externo {$externalId} mudou desde a prévia. Revise novamente."]);
                }
                if ((string) $entry['quantity_total'] !== (string) $row['quantity_total']
                    || (string) $entry['value_total'] !== (string) $row['value_total']
                    || (int) $entry['order_count'] !== (int) $row['order_count']
                    || $entry['prices']['observed'] !== $row['observed_prices']) {
                    throw ValidationException::withMessages(['preview_token' => "Os dados staged de {$externalId} mudaram desde a prévia. Revise novamente."]);
                }
                $category = ProductCategory::query()->whereKey((int) $row['product_category_id'])->where('active', true)->first();
                if ($category === null) {
                    throw ValidationException::withMessages(['preview_token' => 'Uma categoria selecionada foi desativada desde a prévia.']);
                }
                $context = $this->onboarding->context($connection, $externalId, $from, $to);
                $this->onboarding->assertCategoryAllowed($context, $category->id);

                return $this->products->create([
                    'name' => (string) $row['name'],
                    'product_category_id' => $category->id,
                    'stock_unit' => Product::UNIT_COUNT,
                    'active' => (bool) $row['active'],
                    'selling_price' => (string) $row['selling_price'],
                ], [], $user, 'pdv_onboarding_batch', "pdv-product:{$connection->id}:{$externalId}");
            })->all();
        });

        return ['created' => count($created), 'products' => $created];
    }

    /** @return array<string,mixed> */
    private function decrypt(string $token): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw ValidationException::withMessages(['preview_token' => 'A prévia é inválida ou foi adulterada.']);
        }

        if (! is_array($payload) || ($payload['version'] ?? null) !== 1) {
            throw ValidationException::withMessages(['preview_token' => 'A versão da prévia não é suportada.']);
        }

        return $payload;
    }
}
