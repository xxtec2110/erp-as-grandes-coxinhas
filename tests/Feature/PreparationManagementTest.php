<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Preparation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PreparationCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreparationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_preparation_routes_require_authentication(): void
    {
        $this->get(route('preparations.index'))->assertRedirect(route('login'));
        $this->post(route('preparations.store'), [])->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_create_a_preparation(): void
    {
        $response = $this->actingAs(User::factory()->create())->post(route('preparations.store'), [
            'name' => 'Massa base',
            'description' => 'Massa para coxinhas.',
            'initial_quantity' => '10.000000',
            'initial_unit' => 'kg',
            'expected_yield' => '9.200000',
            'yield_unit' => 'kg',
            'actual_final_quantity' => '9.000000',
            'total_preparation_time_minutes' => '75',
            'notes' => 'Processo padrão.',
            'active' => '1',
        ]);

        $preparation = Preparation::query()->firstOrFail();
        $response->assertRedirect(route('preparations.show', $preparation));
        $this->assertSame('10.000000', $preparation->initial_quantity);
        $this->assertSame('9.000000', $preparation->actual_final_quantity);
    }

    public function test_it_adds_ingredients_and_converts_weight_and_volume_units(): void
    {
        $preparation = $this->createPreparation();
        $flour = $this->createPricedIngredient('Farinha', 'g', '0.01000000');
        $oil = $this->createPricedIngredient('Óleo', 'ml', '0.00500000');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('preparations.ingredients.store', $preparation), [
            'ingredient_id' => $flour->id,
            'quantity' => '1.000000',
            'unit' => 'kg',
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->post(route('preparations.ingredients.store', $preparation), [
            'ingredient_id' => $oil->id,
            'quantity' => '2.000000',
            'unit' => 'l',
        ])->assertSessionHasNoErrors();

        $calculation = app(PreparationCostService::class)->calculate($preparation);
        $this->assertSame('1000.000000', $calculation['ingredients'][0]['normalized_quantity']);
        $this->assertSame('2000.000000', $calculation['ingredients'][1]['normalized_quantity']);
        $this->assertSame('20.00000000', $calculation['total_ingredients_cost']);
    }

    public function test_it_rejects_incompatible_or_duplicate_ingredient_units(): void
    {
        $preparation = $this->createPreparation();
        $flour = $this->createPricedIngredient('Farinha', 'g', '0.01000000');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('preparations.ingredients.store', $preparation), [
            'ingredient_id' => $flour->id,
            'quantity' => '1',
            'unit' => 'l',
        ])->assertSessionHasErrors('unit');

        $validData = ['ingredient_id' => $flour->id, 'quantity' => '1', 'unit' => 'kg'];
        $this->actingAs($user)->post(route('preparations.ingredients.store', $preparation), $validData)
            ->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('preparations.ingredients.store', $preparation), $validData)
            ->assertSessionHasErrors('ingredient_id');

        $this->assertDatabaseCount('preparation_ingredients', 1);
    }

    public function test_it_calculates_loss_yield_and_weight_cost_from_actual_final_quantity(): void
    {
        $preparation = $this->createPreparation([
            'initial_quantity' => '10.000000',
            'initial_unit' => 'kg',
            'actual_final_quantity' => '9.000000',
            'yield_unit' => 'kg',
        ]);
        $ingredient = $this->createPricedIngredient('Farinha', 'g', '0.02000000');
        $preparation->preparationIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'quantity' => '1.000000',
            'unit' => 'kg',
        ]);

        $calculation = app(PreparationCostService::class)->calculate($preparation);

        $this->assertSame('20.00000000', $calculation['total_ingredients_cost']);
        $this->assertSame('1000.000000', $calculation['yield']['loss']);
        $this->assertSame('10.0000', $calculation['yield']['loss_percentage']);
        $this->assertSame('90.0000', $calculation['yield']['yield_percentage']);
        $this->assertSame('0.00222222', $calculation['unit_costs']['base_unit_cost']);
        $this->assertSame('2.2222', $calculation['unit_costs']['large_unit_cost']);
    }

    public function test_it_calculates_cost_per_final_unit_for_counted_output(): void
    {
        $preparation = $this->createPreparation([
            'initial_quantity' => '110.000000',
            'initial_unit' => 'un',
            'expected_yield' => '100.000000',
            'actual_final_quantity' => '100.000000',
            'yield_unit' => 'un',
        ]);
        $ingredient = $this->createPricedIngredient('Embalagem', 'un', '0.50000000');
        $preparation->preparationIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'quantity' => '100.000000',
            'unit' => 'un',
        ]);

        $calculation = app(PreparationCostService::class)->calculate($preparation);

        $this->assertSame('0.50000000', $calculation['unit_costs']['base_unit_cost']);
        $this->assertNull($calculation['unit_costs']['large_unit']);
    }

    public function test_changing_current_ingredient_price_updates_preparation_cost(): void
    {
        $preparation = $this->createPreparation();
        $ingredient = $this->createPricedIngredient('Farinha', 'g', '0.01000000');
        $preparation->preparationIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'quantity' => '1.000000',
            'unit' => 'kg',
        ]);
        $service = app(PreparationCostService::class);

        $this->assertSame('10.00000000', $service->calculate($preparation)['total_ingredients_cost']);

        $ingredient->prices()->where('is_current', true)->update(['is_current' => false]);
        $this->createPrice($ingredient, '0.01200000');

        $this->assertSame('12.00000000', $service->calculate($preparation)['total_ingredients_cost']);
        $this->assertDatabaseCount('ingredient_prices', 2);
    }

    public function test_it_rejects_incompatible_initial_and_final_measurements(): void
    {
        $this->actingAs(User::factory()->create())->post(route('preparations.store'), [
            'name' => 'Medidas incompatíveis',
            'initial_quantity' => '5',
            'initial_unit' => 'kg',
            'expected_yield' => '4',
            'yield_unit' => 'l',
            'actual_final_quantity' => '4',
            'total_preparation_time_minutes' => '30',
            'active' => '1',
        ])->assertSessionHasErrors('actual_final_quantity');

        $this->assertDatabaseCount('preparations', 0);
    }

    /** @param array<string, mixed> $overrides */
    private function createPreparation(array $overrides = []): Preparation
    {
        return Preparation::query()->create(array_merge([
            'name' => 'Preparação teste',
            'initial_quantity' => '10.000000',
            'initial_unit' => 'kg',
            'expected_yield' => '9.000000',
            'yield_unit' => 'kg',
            'actual_final_quantity' => '9.000000',
            'total_preparation_time_minutes' => 60,
            'active' => true,
        ], $overrides));
    }

    private function createPricedIngredient(string $name, string $baseUnit, string $baseCost): Ingredient
    {
        $ingredient = Ingredient::query()->create([
            'name' => $name,
            'base_unit' => $baseUnit,
            'active' => true,
        ]);
        $this->createPrice($ingredient, $baseCost);

        return $ingredient;
    }

    private function createPrice(Ingredient $ingredient, string $baseCost): void
    {
        $supplier = Supplier::query()->firstOrCreate(['name' => 'Fornecedor teste'], ['active' => true]);
        $ingredient->prices()->create([
            'supplier_id' => $supplier->id,
            'purchase_quantity' => '1.0000',
            'purchase_unit' => $ingredient->base_unit,
            'normalized_quantity' => '1.000000',
            'price_paid' => '1.00',
            'base_unit_cost' => $baseCost,
            'effective_date' => '2026-08-07',
            'is_current' => true,
        ]);
    }
}
