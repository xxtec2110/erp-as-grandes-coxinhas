<?php

namespace Tests\Feature;

use App\Models\ProductionEquipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_equipment_routes_require_authentication(): void
    {
        $this->get(route('equipment.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_manage_glp_equipment_and_its_burners(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('equipment.store'), [
            'name' => 'Fogão industrial',
            'type' => 'Fogão',
            'description' => 'Equipamento da cozinha de produção.',
            'energy_source' => 'glp',
            'nominal_glp_consumption_kg_hour' => '1.250000',
            'power' => '18.5000',
            'power_unit' => 'kW',
            'default_utilization_factor' => '0.750',
            'notes' => 'Uso principal no cozimento da massa.',
            'active' => '1',
        ]);

        $equipment = ProductionEquipment::query()->firstOrFail();
        $response->assertRedirect(route('equipment.show', $equipment));
        $this->assertSame('1.250000', $equipment->nominal_glp_consumption_kg_hour);
        $this->assertSame('0.750', $equipment->default_utilization_factor);

        $this->post(route('equipment.burners.store', $equipment), [
            'name' => 'Boca frontal',
            'type' => 'double',
            'nominal_glp_consumption_kg_hour' => '0.420000',
            'power' => '7.2500',
            'power_unit' => 'kW',
            'default_utilization_factor' => '0.650',
            'active' => '1',
        ])->assertRedirect(route('equipment.show', $equipment));

        $burner = $equipment->burners()->firstOrFail();
        $this->assertSame('0.420000', $burner->nominal_glp_consumption_kg_hour);
        $this->assertSame('0.650', $burner->default_utilization_factor);

        $this->put(route('equipment.burners.update', [$equipment, $burner]), [
            'name' => 'Boca frontal ajustada',
            'type' => 'custom',
            'nominal_glp_consumption_kg_hour' => '0.380000',
            'power' => '6.8000',
            'power_unit' => 'kW',
            'default_utilization_factor' => '0.600',
            'active' => '1',
        ])->assertRedirect(route('equipment.show', $equipment));

        $this->assertDatabaseHas('equipment_burners', [
            'id' => $burner->id,
            'name' => 'Boca frontal ajustada',
            'nominal_glp_consumption_kg_hour' => '0.380000',
        ]);
    }

    public function test_burner_cannot_be_added_to_non_glp_equipment(): void
    {
        $equipment = ProductionEquipment::query()->create([
            'name' => 'Forno elétrico',
            'type' => 'Forno',
            'energy_source' => 'electric',
            'power' => '12.0000',
            'power_unit' => 'kW',
            'default_utilization_factor' => '0.800',
            'active' => true,
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('equipment.burners.store', $equipment), [
                'name' => 'Queimador inválido',
                'type' => 'simple',
                'nominal_glp_consumption_kg_hour' => '0.200000',
                'default_utilization_factor' => '0.500',
                'active' => '1',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('equipment_burners', 0);
    }
}
