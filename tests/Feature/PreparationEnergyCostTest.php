<?php

namespace Tests\Feature;

use App\Models\EquipmentBurner;
use App\Models\GlpProduct;
use App\Models\Ingredient;
use App\Models\Preparation;
use App\Models\ProductionEquipment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PreparationCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreparationEnergyCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_energy_and_additional_cost_routes_require_authentication(): void
    {
        $preparation = $this->createPreparation();

        $this->post(route('preparations.energy-usages.store', $preparation), [])
            ->assertRedirect(route('login'));
        $this->post(route('preparations.additional-costs.store', $preparation), [])
            ->assertRedirect(route('login'));
    }

    public function test_user_can_associate_burner_and_edit_time_and_utilization_factor(): void
    {
        [$equipment, $burner] = $this->createGlpEquipment('0.600000');
        $glp = $this->createGlpProduct('10.00000000');
        $preparation = $this->createPreparation();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('preparations.energy-usages.store', $preparation), [
            'production_equipment_id' => $equipment->id,
            'equipment_burner_id' => $burner->id,
            'glp_product_id' => $glp->id,
            'usage_time_minutes' => '60.00',
            'utilization_factor' => '0.500',
        ]);

        $usage = $preparation->energyUsages()->firstOrFail();
        $response->assertRedirect(route('preparations.show', $preparation));

        $this->put(route('preparations.energy-usages.update', [$preparation, $usage]), [
            'production_equipment_id' => $equipment->id,
            'equipment_burner_id' => $burner->id,
            'glp_product_id' => $glp->id,
            'usage_time_minutes' => '45.00',
            'utilization_factor' => '0.750',
        ])->assertRedirect(route('preparations.show', $preparation));

        $this->assertDatabaseHas('preparation_energy_usages', [
            'id' => $usage->id,
            'usage_time_minutes' => '45.00',
            'utilization_factor' => '0.750',
        ]);
    }

    public function test_it_sums_multiple_burners_and_consolidates_all_preparation_costs(): void
    {
        [$equipment, $firstBurner] = $this->createGlpEquipment('0.600000');
        $secondBurner = $equipment->burners()->create([
            'name' => 'Boca traseira',
            'type' => 'simple',
            'nominal_glp_consumption_kg_hour' => '0.300000',
            'default_utilization_factor' => '1.000',
            'active' => true,
        ]);
        $glp = $this->createGlpProduct('10.00000000');
        $preparation = $this->createPreparation();
        $this->addIngredientCost($preparation, '10.00000000');

        foreach ([
            [$firstBurner, '60.00', '0.500'],
            [$secondBurner, '30.00', '1.000'],
        ] as [$burner, $minutes, $factor]) {
            $preparation->energyUsages()->create([
                'production_equipment_id' => $equipment->id,
                'equipment_burner_id' => $burner->id,
                'glp_product_id' => $glp->id,
                'usage_time_minutes' => $minutes,
                'utilization_factor' => $factor,
            ]);
        }
        $preparation->additionalCosts()->create(['description' => 'Mão de obra', 'amount' => '2.50']);

        $calculation = app(PreparationCostService::class)->calculate($preparation);

        $this->assertSame('0.45000000', $calculation['total_glp_consumption_kg']);
        $this->assertSame('4.50000000', $calculation['total_energy_cost']);
        $this->assertSame('2.50000000', $calculation['total_additional_costs']);
        $this->assertSame('17.00000000', $calculation['total_preparation_cost']);
        $this->assertSame('1.7000', $calculation['unit_costs']['large_unit_cost']);
    }

    public function test_equipment_level_consumption_is_used_when_no_burner_is_selected(): void
    {
        $equipment = ProductionEquipment::query()->create([
            'name' => 'Tacho a gás',
            'type' => 'Tacho',
            'energy_source' => 'glp',
            'nominal_glp_consumption_kg_hour' => '1.200000',
            'default_utilization_factor' => '1.000',
            'active' => true,
        ]);
        $glp = $this->createGlpProduct('10.00000000');
        $preparation = $this->createPreparation();
        $preparation->energyUsages()->create([
            'production_equipment_id' => $equipment->id,
            'glp_product_id' => $glp->id,
            'usage_time_minutes' => '30.00',
            'utilization_factor' => '0.500',
        ]);

        $calculation = app(PreparationCostService::class)->calculate($preparation);

        $this->assertSame('0.30000000', $calculation['total_glp_consumption_kg']);
        $this->assertSame('3.00000000', $calculation['total_energy_cost']);
    }

    public function test_changing_current_glp_price_updates_preparation_cost(): void
    {
        [$equipment, $burner] = $this->createGlpEquipment('0.600000');
        $glp = $this->createGlpProduct('10.00000000');
        $preparation = $this->createPreparation();
        $preparation->energyUsages()->create([
            'production_equipment_id' => $equipment->id,
            'equipment_burner_id' => $burner->id,
            'glp_product_id' => $glp->id,
            'usage_time_minutes' => '60.00',
            'utilization_factor' => '0.500',
        ]);
        $service = app(PreparationCostService::class);

        $this->assertSame('3.00000000', $service->calculate($preparation)['total_energy_cost']);

        $glp->prices()->where('is_current', true)->update(['is_current' => false]);
        $this->createGlpPrice($glp, '12.00000000');

        $this->assertSame('3.60000000', $service->calculate($preparation)['total_energy_cost']);
        $this->assertDatabaseCount('glp_prices', 2);
    }

    public function test_it_rejects_burner_from_another_equipment(): void
    {
        [$equipment] = $this->createGlpEquipment('0.600000');
        [, $otherBurner] = $this->createGlpEquipment('0.300000', 'Outro fogão');
        $glp = $this->createGlpProduct('10.00000000');
        $preparation = $this->createPreparation();

        $this->actingAs(User::factory()->create())
            ->post(route('preparations.energy-usages.store', $preparation), [
                'production_equipment_id' => $equipment->id,
                'equipment_burner_id' => $otherBurner->id,
                'glp_product_id' => $glp->id,
                'usage_time_minutes' => '30',
                'utilization_factor' => '1',
            ])
            ->assertSessionHasErrors('equipment_burner_id');

        $this->assertDatabaseCount('preparation_energy_usages', 0);
    }

    private function createPreparation(): Preparation
    {
        return Preparation::query()->create([
            'name' => 'Massa base',
            'initial_quantity' => '10.000000',
            'initial_unit' => 'kg',
            'expected_yield' => '10.000000',
            'yield_unit' => 'kg',
            'actual_final_quantity' => '10.000000',
            'total_preparation_time_minutes' => 60,
            'active' => true,
        ]);
    }

    /** @return array{ProductionEquipment, EquipmentBurner} */
    private function createGlpEquipment(string $consumption, string $name = 'Fogão industrial'): array
    {
        $equipment = ProductionEquipment::query()->create([
            'name' => $name,
            'type' => 'Fogão',
            'energy_source' => 'glp',
            'default_utilization_factor' => '1.000',
            'active' => true,
        ]);
        $burner = $equipment->burners()->create([
            'name' => 'Boca frontal',
            'type' => 'simple',
            'nominal_glp_consumption_kg_hour' => $consumption,
            'default_utilization_factor' => '1.000',
            'active' => true,
        ]);

        return [$equipment, $burner];
    }

    private function createGlpProduct(string $unitCost): GlpProduct
    {
        $glp = GlpProduct::query()->create(['name' => 'P45', 'net_weight_kg' => '45.0000', 'active' => true]);
        $this->createGlpPrice($glp, $unitCost);

        return $glp;
    }

    private function createGlpPrice(GlpProduct $glp, string $unitCost): void
    {
        $glp->prices()->create([
            'quantity_kg' => '45.0000',
            'total_price' => '450.00',
            'unit_cost_per_kg' => $unitCost,
            'effective_date' => '2026-08-07',
            'is_current' => true,
        ]);
    }

    private function addIngredientCost(Preparation $preparation, string $totalCost): void
    {
        $supplier = Supplier::query()->create(['name' => 'Fornecedor teste', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Farinha', 'base_unit' => 'g', 'active' => true]);
        $ingredient->prices()->create([
            'supplier_id' => $supplier->id,
            'purchase_quantity' => '1.0000',
            'purchase_unit' => 'g',
            'normalized_quantity' => '1.000000',
            'price_paid' => '1.00',
            'base_unit_cost' => $totalCost,
            'effective_date' => '2026-08-07',
            'is_current' => true,
        ]);
        $preparation->preparationIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'quantity' => '1.000000',
            'unit' => 'g',
        ]);
    }
}
