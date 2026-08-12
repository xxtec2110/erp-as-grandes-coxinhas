<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class StockManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_routes_are_protected_by_authentication(): void
    {
        $product = Product::query()->create(['name' => 'Produto', 'stock_unit' => 'un', 'active' => true]);
        $location = Location::query()->create(['name' => 'Loja', 'type' => 'store', 'active' => true]);

        $this->get(route('stock.index'))->assertRedirect(route('login'));
        $this->get(route('stock.show', [$product, $location]))->assertRedirect(route('login'));
        $this->get(route('stock.adjustments.create', [$product, $location]))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_record_opening_balance_and_adjustment_from_the_interface(): void
    {
        $user = User::factory()->create();
        $product = Product::query()->create(['name' => 'Costela com Queijo', 'stock_unit' => 'un', 'active' => true]);
        $location = Location::query()->create(['name' => 'Fábrica Ibirá', 'type' => 'production', 'active' => true]);
        $key = (string) Str::uuid();

        $payload = [
            'direction' => 'increase',
            'movement_type' => StockMovementType::OpeningBalance->value,
            'quantity' => '150',
            'operation_date' => '2026-08-08',
            'idempotency_key' => $key,
            'notes' => 'Contagem inicial conferida.',
        ];

        $this->actingAs($user)->post(route('stock.adjustments.store', [$product, $location]), $payload)
            ->assertRedirect(route('stock.show', [$product, $location]));

        $this->post(route('stock.adjustments.store', [$product, $location]), $payload)
            ->assertRedirect(route('stock.show', [$product, $location]));

        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity_delta' => 150,
            'idempotency_key' => $key,
        ]);

        $this->get(route('stock.show', [$product, $location]))
            ->assertOk()
            ->assertSee('Saldo oficial')
            ->assertSee('150 un')
            ->assertSee('Contagem inicial conferida.');
    }

    public function test_adjustment_requires_a_non_zero_positive_quantity_and_a_reason(): void
    {
        $user = User::factory()->create();
        $product = Product::query()->create(['name' => 'Produto', 'stock_unit' => 'un', 'active' => true]);
        $location = Location::query()->create(['name' => 'Loja', 'type' => 'store', 'active' => true]);

        $this->actingAs($user)->post(route('stock.adjustments.store', [$product, $location]), [
            'direction' => 'increase',
            'movement_type' => StockMovementType::OpeningBalance->value,
            'quantity' => '0',
            'operation_date' => '2026-08-08',
            'idempotency_key' => (string) Str::uuid(),
            'notes' => '',
        ])->assertSessionHasErrors(['quantity', 'notes']);

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_movements_cannot_be_updated_or_deleted(): void
    {
        $product = Product::query()->create(['name' => 'Produto', 'stock_unit' => 'un', 'active' => true]);
        $location = Location::query()->create(['name' => 'Loja', 'type' => 'store', 'active' => true]);
        $movement = StockMovement::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'type' => StockMovementType::Adjustment,
            'quantity_delta' => '1',
            'operation_date' => '2026-08-08',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        try {
            $movement->update(['notes' => 'Alterado']);
            $this->fail('O movimento deveria ser imutável.');
        } catch (LogicException) {
            $this->assertNull($movement->fresh()->notes);
        }

        $this->expectException(LogicException::class);
        $movement->delete();
    }
}
