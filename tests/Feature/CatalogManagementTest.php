<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Location;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_administrative_catalogs(): void
    {
        $this->get(route('suppliers.index'))->assertRedirect(route('login'));
        $this->get(route('locations.index'))->assertRedirect(route('login'));
        $this->get(route('ingredients.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_create_supplier(): void
    {
        $response = $this->actingAs(User::factory()->create())->post(route('suppliers.store'), [
            'name' => 'Dom Armando',
            'contact_name' => 'João',
            'phone' => '(17) 99999-9999',
            'notes' => 'Entrega semanal',
            'active' => '1',
        ]);

        $response->assertRedirect(route('suppliers.index'));
        $this->assertDatabaseHas('suppliers', ['name' => 'Dom Armando', 'active' => true]);
    }

    public function test_authenticated_user_can_render_administrative_catalog_screens(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('suppliers.index'))
            ->assertOk()
            ->assertSee('Fornecedores');
        $this->get(route('locations.index'))
            ->assertOk()
            ->assertSee('Unidades operacionais');
        $this->get(route('ingredients.index'))
            ->assertOk()
            ->assertSee('Insumos');
    }

    public function test_authenticated_user_can_create_operational_location(): void
    {
        $response = $this->actingAs(User::factory()->create())->post(route('locations.store'), [
            'name' => 'Termas de Ibirá',
            'type' => Location::TYPE_STORE,
            'active' => '1',
        ]);

        $response->assertRedirect(route('locations.index'));
        $this->assertDatabaseHas('locations', ['name' => 'Termas de Ibirá', 'type' => 'store']);
    }

    public function test_authenticated_user_can_create_ingredient(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('ingredients.store'), [
            'name' => 'Muçarela',
            'base_unit' => 'g',
            'notes' => 'Conservar refrigerada',
            'active' => '1',
        ]);

        $ingredient = Ingredient::query()->where('name', 'Muçarela')->firstOrFail();
        $response->assertRedirect(route('ingredients.show', $ingredient));
        $this->assertSame('g', $ingredient->base_unit);
    }

    public function test_base_unit_cannot_change_after_price_history_exists(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::query()->create(['name' => 'Fornecedor', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Farinha', 'base_unit' => 'g', 'active' => true]);
        $ingredient->prices()->create([
            'supplier_id' => $supplier->id,
            'purchase_quantity' => '1.0000',
            'purchase_unit' => 'kg',
            'normalized_quantity' => '1000.000000',
            'price_paid' => '8.00',
            'base_unit_cost' => '0.00800000',
            'effective_date' => '2026-08-07',
            'is_current' => true,
        ]);

        $this->actingAs($user)->put(route('ingredients.update', $ingredient), [
            'name' => 'Farinha',
            'base_unit' => 'ml',
            'active' => '1',
        ])->assertSessionHasErrors('base_unit');

        $this->assertSame('g', $ingredient->fresh()->base_unit);
    }
}
