<?php

namespace Tests\Feature;

use App\Enums\ProductionStatus;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductionRecord;
use App\Models\User;
use App\Services\StockBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_routes_are_protected(): void
    {
        $this->get(route('production.index'))->assertRedirect(route('login'));
        $this->get(route('production.create'))->assertRedirect(route('login'));
    }

    public function test_planning_does_not_change_stock_and_completion_records_actual_quantity_once(): void
    {
        [$user, $product, $factory] = $this->catalog();
        $payload = [
            'product_id' => $product->id,
            'location_id' => $factory->id,
            'planned_quantity' => '100',
            'operation_date' => '2026-08-07',
            'idempotency_key' => (string) Str::uuid(),
            'notes' => 'Produção matinal.',
        ];

        $this->actingAs($user)->post(route('production.store'), $payload)->assertRedirect();
        $production = ProductionRecord::query()->firstOrFail();

        $this->assertSame(ProductionStatus::Planned, $production->status);
        $this->assertSame('0.000000', app(StockBalanceService::class)->balance($product, $factory));

        $this->post(route('production.complete', $production), ['actual_quantity' => '96'])
            ->assertRedirect(route('production.show', $production));
        $this->post(route('production.complete', $production), ['actual_quantity' => '96'])
            ->assertRedirect(route('production.show', $production));

        $this->assertSame('96.000000', app(StockBalanceService::class)->balance($product, $factory));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('production_records', [
            'id' => $production->id,
            'status' => ProductionStatus::Completed->value,
            'actual_quantity' => 96,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => ProductionRecord::class,
            'reference_id' => (string) $production->id,
        ]);
        $this->assertSame('2026-08-07', $production->fresh()->operation_date->toDateString());
    }

    public function test_cancelled_plan_does_not_change_stock(): void
    {
        [$user, $product, $factory] = $this->catalog();
        $production = ProductionRecord::query()->create([
            'product_id' => $product->id,
            'location_id' => $factory->id,
            'planned_quantity' => '20',
            'operation_date' => '2026-08-08',
            'status' => ProductionStatus::Planned,
            'idempotency_key' => (string) Str::uuid(),
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->post(route('production.cancel', $production))
            ->assertRedirect(route('production.show', $production));

        $this->assertSame(ProductionStatus::Cancelled, $production->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_completed_production_rejects_a_different_quantity_on_retry(): void
    {
        [$user, $product, $factory] = $this->catalog();
        $production = ProductionRecord::query()->create([
            'product_id' => $product->id,
            'location_id' => $factory->id,
            'planned_quantity' => '20',
            'operation_date' => '2026-08-08',
            'status' => ProductionStatus::Planned,
            'idempotency_key' => (string) Str::uuid(),
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->post(route('production.complete', $production), ['actual_quantity' => '20']);
        $this->post(route('production.complete', $production), ['actual_quantity' => '21'])
            ->assertSessionHasErrors('actual_quantity');

        $this->assertSame('20.000000', app(StockBalanceService::class)->balance($product, $factory));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_production_can_only_use_an_active_production_location(): void
    {
        [$user, $product] = $this->catalog();
        $store = Location::query()->create(['name' => 'Loja', 'type' => 'store', 'active' => true]);

        $this->actingAs($user)->post(route('production.store'), [
            'product_id' => $product->id,
            'location_id' => $store->id,
            'planned_quantity' => '10',
            'operation_date' => '2026-08-08',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('location_id');

        $this->assertDatabaseCount('production_records', 0);
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
}
