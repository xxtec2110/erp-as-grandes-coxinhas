<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_from_dashboard(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_access_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Acesso autenticado')
            ->assertSee($user->name)
            ->assertSee('Preparo de Recheios')
            ->assertSee('Montagem das Coxinhas')
            ->assertSee('Configurações técnicas')
            ->assertSee(route('suppliers.index'), false)
            ->assertSee(route('equipment.index'), false)
            ->assertSee(route('glp-products.index'), false);
    }
}
