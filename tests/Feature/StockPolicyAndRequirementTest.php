<?php

namespace Tests\Feature;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\ProductionStatus;
use App\Enums\StockMovementType;
use App\Enums\StockSituation;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductionRecord;
use App\Models\ProductStockPolicy;
use App\Models\User;
use App\Services\ProductionRequirementService;
use App\Services\StockMovementService;
use App\Services\StockPositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockPolicyAndRequirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_routes_are_protected(): void
    {
        $this->get(route('stock-policies.index'))->assertRedirect(route('login'));
        $this->get(route('production-requirements.index'))->assertRedirect(route('login'));
        $this->get(route('reports.operational'))->assertRedirect(route('login'));
    }

    public function test_user_can_create_policy_with_audited_idempotent_history(): void
    {
        [$user, $product, $factory] = $this->catalog();
        $payload = [
            'product_id' => $product->id,
            'location_id' => $factory->id,
            'minimum_quantity' => '80',
            'target_quantity' => '200',
            'production_priority' => '50',
            'active' => '1',
            'idempotency_key' => (string) Str::uuid(),
        ];

        $this->actingAs($user)->post(route('stock-policies.store'), $payload)->assertRedirect();
        $this->post(route('stock-policies.store'), $payload)->assertRedirect();

        $this->assertDatabaseCount('product_stock_policies', 1);
        $this->assertDatabaseCount('product_stock_policy_histories', 1);
        $this->assertDatabaseHas('product_stock_policy_histories', [
            'changed_by' => $user->id,
            'channel' => 'web',
            'new_target_quantity' => 200,
        ]);
    }

    public function test_minimum_cannot_be_greater_than_target(): void
    {
        [$user, $product, $factory] = $this->catalog();

        $this->actingAs($user)->post(route('stock-policies.store'), [
            'product_id' => $product->id,
            'location_id' => $factory->id,
            'minimum_quantity' => '201',
            'target_quantity' => '200',
            'production_priority' => '0',
            'active' => '1',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('target_quantity');

        $this->assertDatabaseCount('product_stock_policies', 0);
    }

    public function test_positions_and_requirements_use_official_balance_and_are_isolated_by_location(): void
    {
        [$user, $product, $factory] = $this->catalog();
        $store = Location::query()->create(['name' => 'Catanduva', 'type' => 'store', 'active' => true]);
        $this->policy($product, $factory, '80', '200', 20, $user);
        $this->policy($product, $store, '30', '100', 10, $user);
        $this->movement($product, $factory, '45', 'factory-stock');
        $this->movement($product, $store, '90', 'store-stock');

        $factoryRow = app(StockPositionService::class)->forLocation($factory)[0];
        $storeRow = app(StockPositionService::class)->forLocation($store)[0];

        $this->assertSame('45.000000', $factoryRow['balance']);
        $this->assertSame('155.000000', $factoryRow['requirement']);
        $this->assertSame(StockSituation::Critical, $factoryRow['situation']);
        $this->assertSame('90.000000', $storeRow['balance']);
        $this->assertSame('10.000000', $storeRow['requirement']);
        $this->assertSame(StockSituation::Attention, $storeRow['situation']);

        $requirements = app(ProductionRequirementService::class)->forLocation($factory);
        $this->assertSame('155.000000', $requirements[0]['requirement']);
    }

    public function test_requirement_is_never_negative_and_equal_target_is_ok(): void
    {
        [$user, $product, $factory] = $this->catalog();
        $this->policy($product, $factory, '20', '100', 0, $user);
        $this->movement($product, $factory, '120', 'stock-above-target');

        $row = app(ProductionRequirementService::class)->forLocation($factory)[0];

        $this->assertSame('0.000000', $row['requirement']);
        $this->assertSame(StockSituation::Ok, $row['situation']);
    }

    public function test_planned_production_reduces_requirement_without_changing_current_stock(): void
    {
        [$user, $product, $factory] = $this->catalog();
        $this->policy($product, $factory, '50', '200', 0, $user);
        $this->movement($product, $factory, '100', 'current-stock');
        ProductionRecord::query()->create([
            'product_id' => $product->id,
            'location_id' => $factory->id,
            'planned_quantity' => '40',
            'operation_date' => '2026-08-08',
            'status' => ProductionStatus::Planned,
            'idempotency_key' => (string) Str::uuid(),
            'created_by' => $user->id,
        ]);

        $row = app(ProductionRequirementService::class)->forLocation($factory)[0];

        $this->assertSame('100.000000', $row['balance']);
        $this->assertSame('40.000000', $row['planned_production']);
        $this->assertSame('60.000000', $row['requirement']);
    }

    /** @return array{User, Product, Location} */
    private function catalog(): array
    {
        return [
            User::factory()->create(),
            Product::query()->create(['name' => 'Frango', 'stock_unit' => 'un', 'active' => true]),
            Location::query()->create(['name' => 'Fábrica', 'type' => 'production', 'active' => true]),
        ];
    }

    private function policy(Product $product, Location $location, string $minimum, string $target, int $priority, User $user): void
    {
        ProductStockPolicy::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'minimum_quantity' => $minimum,
            'target_quantity' => $target,
            'production_priority' => $priority,
            'active' => true,
            'updated_by' => $user->id,
        ]);
    }

    private function movement(Product $product, Location $location, string $quantity, string $key): void
    {
        app(StockMovementService::class)->record(new RecordStockMovementData(
            productId: $product->id,
            locationId: $location->id,
            type: StockMovementType::OpeningBalance,
            quantityDelta: $quantity,
            operationDate: '2026-08-08',
            idempotencyKey: $key,
        ));
    }
}
