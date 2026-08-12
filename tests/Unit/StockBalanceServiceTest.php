<?php

namespace Tests\Unit;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\Product;
use App\Services\StockBalanceService;
use App\Services\StockMovementService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockBalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_balance_is_the_sum_of_official_movements_for_product_and_location(): void
    {
        [$product, $factory, $store] = $this->catalog();
        $movements = app(StockMovementService::class);

        $this->record($movements, $product, $factory, '100.500000', 'factory-opening');
        $this->record($movements, $product, $factory, '-20.250000', 'factory-out');
        $this->record($movements, $product, $store, '20.250000', 'store-in');

        $balances = app(StockBalanceService::class);

        $this->assertSame('80.250000', $balances->balance($product, $factory));
        $this->assertSame('20.250000', $balances->balance($product, $store));
    }

    public function test_balance_can_be_calculated_as_of_the_real_operation_date(): void
    {
        [$product, $factory] = $this->catalog();
        $movements = app(StockMovementService::class);

        $this->record($movements, $product, $factory, '100', 'day-one', '2026-08-07');
        $this->record($movements, $product, $factory, '-30', 'day-two', '2026-08-08');

        $balance = app(StockBalanceService::class)->balance(
            $product,
            $factory,
            CarbonImmutable::parse('2026-08-07'),
        );

        $this->assertSame('100.000000', $balance);
    }

    public function test_repeated_idempotency_key_returns_the_same_movement_without_duplication(): void
    {
        [$product, $factory] = $this->catalog();
        $service = app(StockMovementService::class);
        $data = new RecordStockMovementData(
            productId: $product->id,
            locationId: $factory->id,
            type: StockMovementType::Production,
            quantityDelta: '80',
            operationDate: '2026-08-08',
            idempotencyKey: 'production:10:completed',
        );

        $first = $service->record($data);
        $second = $service->record($data);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('80.000000', app(StockBalanceService::class)->balance($product, $factory));
    }

    public function test_idempotency_key_cannot_be_reused_with_another_payload(): void
    {
        [$product, $factory] = $this->catalog();
        $service = app(StockMovementService::class);

        $this->record($service, $product, $factory, '10', 'same-key');

        $this->expectException(DomainException::class);
        $this->record($service, $product, $factory, '20', 'same-key');
    }

    /** @return array{Product, Location, Location} */
    private function catalog(): array
    {
        return [
            Product::query()->create(['name' => 'Frango', 'stock_unit' => 'un', 'active' => true]),
            Location::query()->create(['name' => 'Fábrica', 'type' => 'production', 'active' => true]),
            Location::query()->create(['name' => 'Loja', 'type' => 'store', 'active' => true]),
        ];
    }

    private function record(
        StockMovementService $service,
        Product $product,
        Location $location,
        string $quantity,
        string $key,
        string $date = '2026-08-08',
    ): void {
        $service->record(new RecordStockMovementData(
            productId: $product->id,
            locationId: $location->id,
            type: StockMovementType::Adjustment,
            quantityDelta: $quantity,
            operationDate: $date,
            idempotencyKey: $key,
        ));
    }
}
