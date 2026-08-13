<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseCandidateSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_login_and_critical_authenticated_pages_render(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $location = Location::query()->create(['name' => 'Unidade RC', 'type' => 'production', 'active' => true]);
        $admin = User::factory()->create(['default_location_id' => $location->id]);

        $this->get('/up')->assertOk();
        $this->get(route('login'))->assertOk()->assertSee('As Grandes Coxinhas');

        foreach (['dashboard', 'production-orders.index', 'stock.index', 'ingredient-stock.index', 'purchases.index', 'finance.index', 'agent.simulator', 'agent.observability'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
    }

    public function test_critical_pages_redirect_anonymous_visitors_to_login(): void
    {
        foreach (['dashboard', 'production-orders.index', 'stock.index', 'ingredient-stock.index', 'purchases.index', 'finance.index', 'agent.simulator'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }
}
