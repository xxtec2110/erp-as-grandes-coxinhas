<?php

namespace Tests\Feature;

use App\Agent\AgentToolExecutor;
use App\Models\Ingredient;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseDocument;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\AgentAccessManagementService;
use App\Services\ProductRecipeCostService;
use App\Services\ProductRecipeService;
use App\Services\PurchaseReceiptService;
use App\Services\UserAccessService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationalConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_location_must_be_authorized_and_is_persisted(): void
    {
        $actor = User::factory()->create();
        $user = User::factory()->unprivileged()->create();
        $allowed = Location::query()->create(['name' => 'Loja', 'type' => 'store', 'active' => true]);
        $other = Location::query()->create(['name' => 'Outra', 'type' => 'store', 'active' => true]);
        app(UserAccessService::class)->update($user, ['location_ids' => [$allowed->id], 'default_location_id' => $allowed->id], $actor);
        $this->assertSame($allowed->id, $user->fresh()->default_location_id);
        $this->expectException(ValidationException::class);
        app(UserAccessService::class)->update($user, ['location_ids' => [$allowed->id], 'default_location_id' => $other->id], $actor);
    }

    public function test_purchase_receipt_normalizes_stock_and_is_idempotent(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $location = Location::query()->create(['name' => 'Fábrica', 'type' => 'production', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Farinha', 'base_unit' => 'g', 'active' => true]);
        $document = PurchaseDocument::query()->create(['document_type' => 'invoice', 'issue_date' => now(), 'total_amount' => '20', 'location_id' => $location->id, 'idempotency_key' => 'receipt-doc']);
        $document->items()->create(['ingredient_id' => $ingredient->id, 'description' => 'Farinha', 'quantity' => '2.5', 'unit' => 'kg', 'unit_price' => '8', 'total_price' => '20']);
        app(PurchaseReceiptService::class)->receive($document, now()->toDateString(), $user);
        app(PurchaseReceiptService::class)->receive($document->fresh(), now()->toDateString(), $user);
        $this->assertDatabaseCount('ingredient_stock_movements', 1);
        $this->assertDatabaseHas('ingredient_stock_movements', ['ingredient_id' => $ingredient->id, 'quantity_delta' => '2500.000000']);
    }

    public function test_purchase_can_be_received_in_idempotent_partial_events(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $location = Location::query()->create(['name' => 'Fábrica', 'type' => 'production', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Farinha', 'base_unit' => 'g', 'active' => true]);
        $document = PurchaseDocument::query()->create(['document_type' => 'invoice', 'issue_date' => now(), 'total_amount' => '80', 'location_id' => $location->id, 'idempotency_key' => 'partial-doc']);
        $item = $document->items()->create(['ingredient_id' => $ingredient->id, 'description' => 'Farinha', 'quantity' => '10', 'unit' => 'kg', 'unit_price' => '8', 'total_price' => '80']);
        $service = app(PurchaseReceiptService::class);

        $service->receivePartial($document, now()->toDateString(), [$item->id => '4'], 'partial-event-1', $user);
        $service->receivePartial($document->fresh(), now()->toDateString(), [$item->id => '4'], 'partial-event-1', $user);
        $this->assertSame('partially_received', $document->fresh()->receipt_status);
        $this->assertSame('4.000000', $item->fresh()->received_quantity);
        $this->assertDatabaseCount('ingredient_stock_movements', 1);

        $service->receivePartial($document->fresh(), now()->toDateString(), [$item->id => '6'], 'partial-event-2', $user);
        $this->assertSame('received', $document->fresh()->receipt_status);
        $this->assertSame('10.000000', $item->fresh()->received_quantity);
        $this->assertDatabaseCount('ingredient_stock_movements', 2);
        $this->assertDatabaseHas('ingredient_stock_movements', ['quantity_delta' => '4000.000000']);
        $this->assertDatabaseHas('ingredient_stock_movements', ['quantity_delta' => '6000.000000']);
    }

    public function test_purchase_receipt_rejects_quantity_above_pending_balance(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $location = Location::query()->create(['name' => 'Fábrica', 'type' => 'production', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Farinha', 'base_unit' => 'g', 'active' => true]);
        $document = PurchaseDocument::query()->create(['document_type' => 'invoice', 'issue_date' => now(), 'total_amount' => '80', 'location_id' => $location->id, 'idempotency_key' => 'over-receipt-doc']);
        $item = $document->items()->create(['ingredient_id' => $ingredient->id, 'description' => 'Farinha', 'quantity' => '10', 'unit' => 'kg', 'unit_price' => '8', 'total_price' => '80']);

        $this->expectException(\DomainException::class);
        app(PurchaseReceiptService::class)->receivePartial($document, now()->toDateString(), [$item->id => '11'], 'over-receipt-event', $user);
    }

    public function test_audio_grant_updates_permission_identity_and_audit(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $actor = User::factory()->create();
        $target = User::factory()->unprivileged()->create(['name' => 'Guilherme']);
        UserExternalIdentity::query()->create(['user_id' => $target->id, 'channel' => 'local-test', 'external_user_id' => 'guilherme', 'status' => 'approved', 'active' => true, 'voice_allowed' => false]);
        app(AgentAccessManagementService::class)->permission(['target_user_name' => 'Guilherme', 'permission' => 'agent.audio.use'], $actor, true);
        $permission = Permission::query()->where('name', 'agent.audio.use')->firstOrFail();
        $this->assertTrue((bool) $target->permissions()->whereKey($permission)->firstOrFail()->pivot->allowed);
        $this->assertTrue($target->externalIdentities()->firstOrFail()->voice_allowed);
        $this->assertDatabaseHas('authorization_audits', ['actor_user_id' => $actor->id, 'target_user_id' => $target->id, 'source' => 'agent']);
    }

    public function test_new_write_tools_require_confirmation(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        foreach (['losses.record', 'production.complete', 'transfers.create', 'transfers.dispatch', 'transfers.receive', 'agent.access.permission.grant'] as $tool) {
            try {
                app(AgentToolExecutor::class)->execute($tool, [], $user);
                $this->fail("{$tool} deveria exigir confirmação.");
            } catch (\DomainException $exception) {
                $this->assertSame('Esta operação exige confirmação.', $exception->getMessage());
            }
        }
    }

    public function test_product_recipe_recalculates_current_direct_cost(): void
    {
        $product = Product::query()->create(['name' => 'Coxinha', 'stock_unit' => 'un', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Queijo', 'base_unit' => 'g', 'active' => true]);
        $supplier = Supplier::query()->create(['name' => 'Fornecedor', 'active' => true]);
        $ingredient->prices()->create(['supplier_id' => $supplier->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'price_paid' => '40', 'effective_date' => now(), 'normalized_quantity' => '1000', 'base_unit_cost' => '0.04', 'is_current' => true]);
        $recipe = app(ProductRecipeService::class)->save($product, ['yield_quantity' => '1', 'technical_loss_percentage' => '0', 'packaging_cost' => '1', 'selling_price' => '12', 'ingredients' => [['ingredient_id' => $ingredient->id, 'quantity' => '100', 'unit' => 'g']]]);
        $cost = app(ProductRecipeCostService::class)->calculate($recipe);
        $this->assertSame('5.00000000', $cost['direct_cost']);
        $this->assertSame('7.0000', $cost['gross_profit']);
        $ingredient->prices()->update(['base_unit_cost' => '0.05']);
        $this->assertSame('6.00000000', app(ProductRecipeCostService::class)->calculate($recipe)['direct_cost']);
    }
}
