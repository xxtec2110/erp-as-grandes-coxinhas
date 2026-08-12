<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdministrativeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_global_catalog_requires_view_and_manage_permissions_on_direct_requests(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Fornecedor', 'active' => true]);
        $ordinary = User::factory()->unprivileged()->create();
        $viewer = $this->userWith('suppliers.view');
        $manager = $this->userWith('suppliers.manage');

        $this->actingAs($ordinary)->get(route('suppliers.index'))->assertForbidden();
        $this->actingAs($ordinary)->post(route('suppliers.store'), [])->assertForbidden();
        $this->actingAs($viewer)->get(route('suppliers.index'))->assertOk();
        $this->actingAs($viewer)->put(route('suppliers.update', $supplier), [])->assertForbidden();
        $this->actingAs($manager)->get(route('suppliers.edit', $supplier))->assertOk();
        $this->actingAs($ordinary)->get(route('equipment.index'))->assertForbidden();
        $this->actingAs($this->userWith('catalogs.manage'))->get(route('equipment.index'))->assertOk();
    }

    public function test_location_update_requires_permission_and_matching_location_scope(): void
    {
        $allowed = Location::query()->create(['name' => 'Catanduva', 'type' => 'store', 'active' => true]);
        $other = Location::query()->create(['name' => 'Ibirá', 'type' => 'production', 'active' => true]);
        $user = $this->userWith('locations.update');
        $user->locations()->sync([$allowed->id]);

        $this->actingAs($user)->get(route('locations.edit', $allowed))->assertOk();
        $this->actingAs($user)->get(route('locations.edit', $other))->assertForbidden();
        $this->actingAs($user)->put(route('locations.update', $other), ['name' => 'Tentativa', 'type' => 'store', 'active' => 1])->assertForbidden();
        $this->actingAs($this->userWith('locations.create'))->get(route('locations.create'))->assertOk();
    }

    public function test_ingredient_view_create_and_update_are_independent_and_override_deny_wins(): void
    {
        $ingredient = Ingredient::query()->create(['name' => 'Farinha', 'base_unit' => 'g', 'active' => true]);
        $viewer = $this->userWith('ingredients.view');
        $creator = $this->userWith('ingredients.create');
        $updater = $this->userWith('ingredients.update');

        $this->actingAs($viewer)->get(route('ingredients.index'))->assertOk();
        $this->actingAs($viewer)->get(route('ingredients.create'))->assertForbidden();
        $this->actingAs($creator)->get(route('ingredients.create'))->assertOk();
        $this->actingAs($creator)->get(route('ingredients.edit', $ingredient))->assertForbidden();
        $this->actingAs($updater)->get(route('ingredients.edit', $ingredient))->assertOk();

        $adminRoleUser = User::factory()->unprivileged()->create();
        $adminRoleUser->roles()->attach(Role::query()->where('name', 'administrator')->firstOrFail());
        $adminRoleUser->permissions()->attach(Permission::query()->where('name', 'ingredients.update')->firstOrFail(), ['allowed' => false]);
        $this->actingAs($adminRoleUser)->get(route('ingredients.edit', $ingredient))->assertForbidden();
    }

    public function test_super_admin_bypass_remains_central_and_administrative_routes_stay_protected(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $ordinary = User::factory()->unprivileged()->create();

        $this->actingAs($admin)->get(route('users.index'))->assertOk();
        $this->actingAs($admin)->get(route('preparations.create'))->assertOk();
        $this->actingAs($ordinary)->get(route('users.index'))->assertForbidden();
        $this->actingAs($ordinary)->get(route('preparations.index'))->assertForbidden();
        $this->actingAs($ordinary)->post(route('loss-reasons.store'), [])->assertForbidden();
    }

    private function userWith(string $permission): User
    {
        $user = User::factory()->unprivileged()->create();
        $user->permissions()->attach(Permission::query()->where('name', $permission)->firstOrFail(), ['allowed' => true]);

        return $user;
    }
}
