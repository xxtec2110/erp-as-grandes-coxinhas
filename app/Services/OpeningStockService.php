<?php

namespace App\Services;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class OpeningStockService
{
    public function __construct(
        private AuthorizationService $authorization,
        private StockMovementService $movements,
    ) {}

    /** @param array<string, mixed> $data
     * @return array{product: Product, location: Location, quantity: string, operation_date: string, notes: string, idempotency_key: string, token: string}
     */
    public function preview(array $data, User $user): array
    {
        $product = Product::query()->where('active', true)->findOrFail($data['product_id']);
        $location = Location::query()->where('active', true)->findOrFail($data['location_id']);
        $this->authorization->authorize($user, 'stock.opening_balance', $location);
        $this->assertAvailable($product, $location, (string) $data['idempotency_key']);

        $quantity = (string) BigDecimal::of((string) $data['quantity'])->toScale(6, RoundingMode::Unnecessary);
        $payload = [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'operation_date' => (string) $data['operation_date'],
            'notes' => (string) $data['notes'],
            'idempotency_key' => (string) $data['idempotency_key'],
            'expires_at' => now()->addMinutes(30)->timestamp,
        ];

        return [
            'product' => $product,
            'location' => $location,
            'quantity' => $quantity,
            'operation_date' => $payload['operation_date'],
            'notes' => $payload['notes'],
            'idempotency_key' => $payload['idempotency_key'],
            'token' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
    }

    public function confirm(string $token, User $user): StockMovement
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new DomainException('A prévia do estoque inicial é inválida. Gere uma nova prévia.');
        }

        if ((int) ($payload['user_id'] ?? 0) !== $user->id || (int) ($payload['expires_at'] ?? 0) < now()->timestamp) {
            throw new DomainException('A prévia do estoque inicial expirou ou pertence a outro usuário.');
        }

        return $this->record($payload, $user);
    }

    /** @param array<string, mixed> $data */
    public function record(array $data, User $user): StockMovement
    {
        $this->assertPayload($data);
        $product = Product::query()->where('active', true)->findOrFail($data['product_id']);
        $location = Location::query()->where('active', true)->findOrFail($data['location_id']);
        $this->authorization->authorize($user, 'stock.opening_balance', $location);

        return $this->movements->record(new RecordStockMovementData(
            productId: $product->id,
            locationId: $location->id,
            type: StockMovementType::OpeningBalance,
            quantityDelta: (string) $data['quantity'],
            operationDate: (string) $data['operation_date'],
            idempotencyKey: (string) $data['idempotency_key'],
            createdBy: $user->id,
            notes: (string) ($data['notes'] ?? 'Estoque inicial confirmado.'),
            referenceType: 'opening_stock',
            referenceId: (string) $data['idempotency_key'],
        ));
    }

    /** @param array<string, mixed> $data */
    private function assertPayload(array $data): void
    {
        foreach (['product_id', 'location_id', 'quantity', 'operation_date', 'notes', 'idempotency_key'] as $field) {
            if (! isset($data[$field]) || trim((string) $data[$field]) === '') {
                throw new DomainException('O campo '.$field.' é obrigatório para registrar o estoque inicial.');
            }
        }
        if (! BigDecimal::of((string) $data['quantity'])->isPositive()) {
            throw new DomainException('A quantidade do estoque inicial deve ser maior que zero.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['operation_date']) !== 1) {
            throw new DomainException('Informe a data real da operação no formato AAAA-MM-DD.');
        }
    }

    private function assertAvailable(Product $product, Location $location, string $idempotencyKey): void
    {
        if (StockMovement::query()->where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }
        if (StockMovement::query()->whereBelongsTo($product)->whereBelongsTo($location)->exists()) {
            throw new DomainException('Este produto já possui histórico nesta unidade. Use um ajuste auditável, não um novo estoque inicial.');
        }
    }
}
