<?php

namespace Tests\Feature;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\StockBalanceService;
use App\Services\StockMovementService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthorizationAndSalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_role_permission_direct_denial_and_location_scope_are_centralized(): void
    {
        $allowed = Location::query()->create(['name' => 'Loja autorizada', 'type' => 'store', 'active' => true]);
        $blocked = Location::query()->create(['name' => 'Outra loja', 'type' => 'store', 'active' => true]);
        $user = User::factory()->unprivileged()->create();
        $role = Role::query()->where('name', 'store')->firstOrFail();
        $permission = Permission::query()->where('name', 'stock.view')->firstOrFail();
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);
        $user->locations()->attach($allowed);

        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->allows($user, 'stock.view', $allowed));
        $this->assertFalse($authorization->allows($user, 'stock.view', $blocked));

        $user->permissions()->attach($permission, ['allowed' => false]);
        $this->assertFalse($authorization->allows($user, 'stock.view', $allowed));
    }

    public function test_unauthorized_direct_url_is_forbidden_and_access_changes_are_audited(): void
    {
        $operator = User::factory()->unprivileged()->create();
        $admin = User::factory()->create();

        $this->actingAs($operator)->get(route('users.index'))->assertForbidden();
        $this->actingAs($admin)->put(route('users.access.update', $operator), [
            'role_ids' => [],
            'location_ids' => [],
            'permission_overrides' => [],
            'all_locations_access' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('authorization_audits', [
            'actor_user_id' => $admin->id,
            'target_user_id' => $operator->id,
            'change_type' => 'access_updated',
        ]);
    }

    public function test_product_category_can_be_assigned_to_product(): void
    {
        $user = User::factory()->create();
        $category = ProductCategory::query()->create(['name' => 'Coxinhas', 'active' => true]);

        $this->actingAs($user)->post(route('products.store'), [
            'name' => 'Frango com Catupiry',
            'product_category_id' => $category->id,
            'stock_unit' => 'un',
            'active' => true,
        ])->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['name' => 'Frango com Catupiry', 'product_category_id' => $category->id]);
    }

    public function test_sale_snapshots_price_reduces_official_stock_once_and_respects_location(): void
    {
        $location = Location::query()->create(['name' => 'Loja Ibirá', 'type' => 'store', 'active' => true]);
        $other = Location::query()->create(['name' => 'Loja externa', 'type' => 'store', 'active' => true]);
        $product = Product::query()->create(['name' => 'Costela', 'stock_unit' => 'un', 'active' => true]);
        $user = User::factory()->unprivileged()->create();
        $user->locations()->attach($location);
        $user->permissions()->attach(Permission::query()->where('name', 'sales.create')->firstOrFail(), ['allowed' => true]);
        $this->openingStock($product, $location, '10');
        $key = (string) Str::uuid();
        $payload = ['product_id' => $product->id, 'location_id' => $location->id, 'quantity' => '2', 'unit_price' => '12.50', 'operation_date' => '2026-08-08', 'idempotency_key' => $key];

        $this->actingAs($user)->post(route('sales.store'), $payload)->assertRedirect(route('sales.index'));
        $this->post(route('sales.store'), $payload)->assertRedirect(route('sales.index'));

        $this->assertDatabaseCount('product_sales', 1);
        $this->assertDatabaseHas('product_sales', ['unit_price' => '12.5000', 'total_amount' => '25.00']);
        $this->assertDatabaseHas('stock_movements', ['type' => StockMovementType::Sale->value, 'quantity_delta' => '-2.000000']);
        $this->assertSame('8.000000', app(StockBalanceService::class)->balance($product, $location));

        $user->permissions()->attach(Permission::query()->where('name', 'sales.view')->firstOrFail(), ['allowed' => true]);
        $this->get(route('sales.index'))->assertOk()->assertSee('Vendas');

        $this->post(route('sales.store'), [...$payload, 'location_id' => $other->id, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
    }

    private function openingStock(Product $product, Location $location, string $quantity): void
    {
        app(StockMovementService::class)->record(new RecordStockMovementData(
            productId: $product->id,
            locationId: $location->id,
            type: StockMovementType::OpeningBalance,
            quantityDelta: $quantity,
            operationDate: '2026-08-08',
            idempotencyKey: (string) Str::uuid(),
        ));
    }
}
