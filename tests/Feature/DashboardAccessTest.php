<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_from_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_access_dashboard(): void
    {
        $user = User::factory()->create();
        Location::query()->create(['name' => 'Unidade de teste', 'type' => 'store', 'active' => true]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()->assertSee('Dashboard de Gestão')->assertSee('Faturamento bruto confirmado')
            ->assertSee(route('suppliers.index'), false)->assertSee(route('equipment.index'), false)->assertSee(route('glp-products.index'), false);
    }
}
