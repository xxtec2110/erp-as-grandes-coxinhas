<?php

namespace Tests\Feature;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_products(): void
    {
        $this->get(route('products.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_create_and_edit_a_product(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('products.store'), [
            'name' => 'Frango com Catupiry',
            'stock_unit' => Product::UNIT_COUNT,
            'active' => '1',
        ])->assertRedirect(route('products.index'));

        $product = Product::query()->firstOrFail();

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Frango com Catupiry');

        $this->put(route('products.update', $product), [
            'name' => 'Frango com Requeijão',
            'stock_unit' => Product::UNIT_COUNT,
            'active' => '1',
        ])->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Frango com Requeijão',
            'stock_unit' => 'un',
        ]);
    }

    public function test_stock_unit_cannot_change_after_a_movement_exists(): void
    {
        $user = User::factory()->create();
        $product = Product::query()->create(['name' => 'Produto', 'stock_unit' => 'un', 'active' => true]);
        $location = Location::query()->create(['name' => 'Fábrica', 'type' => 'production', 'active' => true]);

        app(StockMovementService::class)->record(new RecordStockMovementData(
            productId: $product->id,
            locationId: $location->id,
            type: StockMovementType::OpeningBalance,
            quantityDelta: '10',
            operationDate: '2026-08-08',
            idempotencyKey: (string) Str::uuid(),
            createdBy: $user->id,
        ));

        $this->actingAs($user)->put(route('products.update', $product), [
            'name' => 'Produto',
            'stock_unit' => 'g',
            'active' => '1',
        ])->assertSessionHasErrors('stock_unit');

        $this->assertSame('un', $product->fresh()->stock_unit);
    }
}
