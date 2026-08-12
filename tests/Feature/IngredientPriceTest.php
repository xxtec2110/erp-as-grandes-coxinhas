<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngredientPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_calculates_normalized_cost_and_makes_first_price_current(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::query()->create(['name' => 'Dom Armando', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Muçarela', 'base_unit' => 'g', 'active' => true]);

        $response = $this->actingAs($user)->post(route('ingredients.prices.store', $ingredient), [
            'supplier_id' => $supplier->id,
            'purchase_quantity' => '5',
            'purchase_unit' => 'kg',
            'price_paid' => '220.00',
            'effective_date' => '2026-08-07',
        ]);

        $response->assertRedirect(route('ingredients.show', $ingredient));
        $this->assertDatabaseHas('ingredient_prices', [
            'ingredient_id' => $ingredient->id,
            'normalized_quantity' => '5000.000000',
            'base_unit_cost' => '0.04400000',
            'is_current' => true,
        ]);

        $this->get(route('ingredients.show', $ingredient))
            ->assertOk()
            ->assertSee('R$ 44,00')
            ->assertSee('R$ 0,0440');
    }

    public function test_new_current_price_keeps_previous_price_in_history(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::query()->create(['name' => 'Fornecedor', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Requeijão', 'base_unit' => 'g', 'active' => true]);

        foreach ([['50.00', '2026-08-01'], ['52.00', '2026-08-07']] as [$price, $date]) {
            $this->actingAs($user)->post(route('ingredients.prices.store', $ingredient), [
                'supplier_id' => $supplier->id,
                'purchase_quantity' => '1.5',
                'purchase_unit' => 'kg',
                'price_paid' => $price,
                'effective_date' => $date,
                'is_current' => '1',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, $ingredient->prices()->count());
        $this->assertSame(1, $ingredient->prices()->where('is_current', true)->count());
        $this->assertSame('52.00', $ingredient->currentPrice()->firstOrFail()->price_paid);
    }

    public function test_it_rejects_incompatible_purchase_unit(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::query()->create(['name' => 'Fornecedor', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Óleo', 'base_unit' => 'ml', 'active' => true]);

        $this->actingAs($user)->post(route('ingredients.prices.store', $ingredient), [
            'supplier_id' => $supplier->id,
            'purchase_quantity' => '5',
            'purchase_unit' => 'kg',
            'price_paid' => '40.00',
            'effective_date' => '2026-08-07',
            'is_current' => '1',
        ])->assertSessionHasErrors('purchase_unit');

        $this->assertDatabaseCount('ingredient_prices', 0);
    }
}
