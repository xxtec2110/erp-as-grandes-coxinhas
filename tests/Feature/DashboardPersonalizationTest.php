<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserDashboardWidget;
use App\Services\DashboardWidgetRegistry;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardPersonalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_master_sees_every_registered_widget_and_empty_data_does_not_break_dashboard(): void
    {
        $master = User::factory()->create();
        $location = $this->location();
        $response = $this->actingAs($master)->get(route('dashboard', ['location_id' => $location->id]));

        $response->assertOk()->assertSee('Dashboard de Gestão');
        foreach (app(DashboardWidgetRegistry::class)->keys() as $key) {
            $response->assertSee('data-widget-key="'.$key.'"', false);
        }
        $response->assertSee('Sem dados no período');
    }

    public function test_common_user_only_receives_widgets_allowed_by_functional_permission_and_visibility(): void
    {
        $location = $this->location();
        $user = $this->restrictedUser($location, ['sales.view']);
        UserDashboardWidget::query()->create(['user_id' => $user->id, 'widget_key' => 'dashboard.sales_quantity', 'visibility' => 'show']);
        UserDashboardWidget::query()->create(['user_id' => $user->id, 'widget_key' => 'dashboard.daily_goal', 'visibility' => 'hide']);
        UserDashboardWidget::query()->create(['user_id' => $user->id, 'widget_key' => 'dashboard.top_flavors', 'visibility' => 'hide']);
        UserDashboardWidget::query()->create(['user_id' => $user->id, 'widget_key' => 'dashboard.operational_alerts', 'visibility' => 'hide']);

        $response = $this->actingAs($user)->get(route('dashboard', ['location_id' => $location->id]));
        $response->assertOk()->assertSee('data-widget-key="dashboard.sales_quantity"', false)
            ->assertDontSee('data-widget-key="dashboard.revenue"', false)
            ->assertDontSee('data-widget-key="dashboard.cash_flow"', false)
            ->assertDontSee('Contas a pagar');
    }

    public function test_hidden_widget_is_not_queried_or_sent_to_browser(): void
    {
        $location = $this->location();
        $user = $this->restrictedUser($location, ['sales.view']);
        foreach (['dashboard.sales_quantity', 'dashboard.daily_goal', 'dashboard.top_flavors', 'dashboard.operational_alerts'] as $key) {
            UserDashboardWidget::query()->create(['user_id' => $user->id, 'widget_key' => $key, 'visibility' => 'hide']);
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($user)->get(route('dashboard', ['location_id' => $location->id]));
        $response->assertOk()->assertDontSee('data-widget-key="dashboard.sales_quantity"', false);
        $this->assertFalse(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'product_sales')));
    }

    public function test_widget_preference_cannot_bypass_missing_functional_permission(): void
    {
        $location = $this->location();
        $user = $this->restrictedUser($location);
        UserDashboardWidget::query()->create(['user_id' => $user->id, 'widget_key' => 'dashboard.revenue', 'visibility' => 'show']);

        $this->actingAs($user)->get(route('dashboard', ['location_id' => $location->id]))
            ->assertOk()->assertDontSee('data-widget-key="dashboard.revenue"', false)->assertDontSee('Faturamento bruto confirmado');
    }

    public function test_widget_with_visibility_and_functional_permissions_is_rendered(): void
    {
        $location = $this->location();
        $user = $this->restrictedUser($location, ['sales.view', 'dashboard.financial.view']);
        UserDashboardWidget::query()->create(['user_id' => $user->id, 'widget_key' => 'dashboard.revenue', 'visibility' => 'show']);

        $this->actingAs($user)->get(route('dashboard', ['location_id' => $location->id]))
            ->assertOk()->assertSee('data-widget-key="dashboard.revenue"', false)->assertSee('Faturamento bruto confirmado');
    }

    public function test_location_scope_is_strict_even_when_request_is_manipulated(): void
    {
        $allowed = $this->location('Permitida');
        $blocked = $this->location('Bloqueada');
        $user = $this->restrictedUser($allowed, ['stock.view']);

        $this->actingAs($user)->get(route('dashboard', ['location_id' => $allowed->id]))->assertOk()->assertSee('Permitida');
        $this->get(route('dashboard', ['location_id' => $blocked->id]))->assertForbidden()->assertDontSee('Bloqueada');
    }

    public function test_master_can_configure_dashboard_in_user_access_and_change_is_audited(): void
    {
        $master = User::factory()->create();
        $target = User::factory()->unprivileged()->create();
        $target->permissions()->attach(Permission::query()->where('name', 'sales.view')->firstOrFail(), ['allowed' => true]);

        $this->actingAs($master)->get(route('users.access.edit', $target))->assertOk()->assertSee('Visibilidade do dashboard');
        $this->put(route('users.dashboard.update', $target), ['widgets' => ['dashboard.sales_quantity' => 'show', 'dashboard.daily_goal' => 'hide']])->assertRedirect();

        $this->assertDatabaseHas('user_dashboard_widgets', ['user_id' => $target->id, 'widget_key' => 'dashboard.sales_quantity', 'visibility' => 'show']);
        $this->assertDatabaseHas('authorization_audits', ['actor_user_id' => $master->id, 'target_user_id' => $target->id, 'change_type' => 'dashboard_visibility_updated', 'source' => 'web']);
    }

    public function test_common_user_cannot_manage_dashboard_or_use_unknown_widget_key(): void
    {
        $master = User::factory()->create();
        $common = User::factory()->unprivileged()->create();
        $this->actingAs($common)->put(route('users.dashboard.update', $common), ['widgets' => ['dashboard.sales_quantity' => 'show']])->assertForbidden();

        $this->actingAs($master)->put(route('users.dashboard.update', $common), ['widgets' => ['dashboard.unknown' => 'show']])->assertStatus(422);
        $this->assertDatabaseMissing('user_dashboard_widgets', ['user_id' => $common->id]);
    }

    public function test_existing_main_menu_order_is_preserved(): void
    {
        $user = User::factory()->create();
        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
        $labels = ['Dashboard', 'Insumos', 'Estoque de Insumos', 'Preparo de Recheios', 'Montagem das Coxinhas', 'Produção', 'Produtos', 'Estoque', 'Entradas / Recebimentos', 'Vendas', 'Financeiro', 'Compras', 'Perdas', 'Relatórios', 'Unidades'];
        $last = -1;
        foreach ($labels as $label) {
            $position = strpos($html, $label, $last + 1);
            $this->assertNotFalse($position, "Item de menu ausente: {$label}");
            $this->assertGreaterThan($last, $position, "Ordem incorreta no item: {$label}");
            $last = $position;
        }
    }

    private function location(string $name = 'Unidade Dashboard'): Location
    {
        return Location::query()->create(['name' => $name, 'type' => 'store', 'active' => true]);
    }

    /** @param array<int, string> $permissions */
    private function restrictedUser(Location $location, array $permissions = []): User
    {
        $user = User::factory()->unprivileged()->create(['default_location_id' => $location->id]);
        $user->locations()->attach($location);
        foreach ($permissions as $permission) {
            $user->permissions()->attach(Permission::query()->where('name', $permission)->firstOrFail(), ['allowed' => true]);
        }

        return $user;
    }
}
