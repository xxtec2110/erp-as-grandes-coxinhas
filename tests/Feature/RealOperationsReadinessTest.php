<?php

namespace Tests\Feature;

use App\Agent\AgentMessage;
use App\Agent\ErpAgentService;
use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\IngredientPriceService;
use App\Services\OpeningStockService;
use App\Services\ProductMarginService;
use App\Services\ProductRecipeService;
use Database\Seeders\AuthorizationSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RealOperationsReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_ingredient_without_price_and_incomplete_recipe_never_generate_false_final_cost(): void
    {
        $ingredient = Ingredient::query()->create(['name' => 'Muçarela', 'base_unit' => 'g', 'active' => true]);
        $product = Product::query()->create(['name' => 'Produto sem custo', 'stock_unit' => 'un', 'active' => true]);

        app(ProductRecipeService::class)->save($product, [
            'yield_quantity' => '10',
            'technical_loss_percentage' => '0',
            'packaging_cost' => '2',
            'ingredients' => [['ingredient_id' => $ingredient->id, 'quantity' => '100', 'unit' => 'g']],
        ]);

        $margin = app(ProductMarginService::class)->current($product->fresh(['recipe', 'currentPrice']));
        $this->assertSame('incomplete_cost', $margin['status']);
        $this->assertNull($margin['unit_cost']);
        $this->assertSame('0.20000000', $margin['partial_unit_cost']);
        $this->assertSame(['Insumo: Muçarela'], $margin['missing_components']);
        $this->assertDatabaseCount('product_cost_snapshots', 0);

        $emptyProduct = Product::query()->create(['name' => 'Produto sem componentes', 'stock_unit' => 'un', 'active' => true]);
        app(ProductRecipeService::class)->save($emptyProduct, ['yield_quantity' => '1', 'technical_loss_percentage' => '0', 'packaging_cost' => '0']);
        $emptyMargin = app(ProductMarginService::class)->current($emptyProduct->fresh(['recipe', 'currentPrice']));
        $this->assertNull($emptyMargin['unit_cost']);
        $this->assertContains('Nenhum componente cadastrado', $emptyMargin['missing_components']);
        $this->assertDatabaseCount('product_cost_snapshots', 0);
    }

    public function test_multiple_suppliers_and_new_current_price_preserve_the_complete_history(): void
    {
        $ingredient = Ingredient::query()->create(['name' => 'Farinha', 'base_unit' => 'g', 'active' => true]);
        $firstSupplier = Supplier::query()->create(['name' => 'Fornecedor A', 'active' => true]);
        $secondSupplier = Supplier::query()->create(['name' => 'Fornecedor B', 'active' => true]);
        $prices = app(IngredientPriceService::class);

        $first = $prices->record($ingredient, ['supplier_id' => $firstSupplier->id, 'purchase_quantity' => '5', 'purchase_unit' => 'kg', 'price_paid' => '25', 'effective_date' => '2026-08-01', 'is_current' => true]);
        $second = $prices->record($ingredient, ['supplier_id' => $secondSupplier->id, 'purchase_quantity' => '5', 'purchase_unit' => 'kg', 'price_paid' => '30', 'effective_date' => '2026-08-15', 'is_current' => true]);

        $this->assertDatabaseCount('ingredient_prices', 2);
        $this->assertFalse($first->fresh()->is_current);
        $this->assertTrue($second->fresh()->is_current);
        $this->assertSame($secondSupplier->id, $ingredient->fresh()->currentPrice->supplier_id);
    }

    public function test_opening_stock_uses_preview_confirmation_official_movement_and_idempotency(): void
    {
        $user = User::factory()->create();
        $product = Product::query()->create(['name' => 'Frango com Catupiry', 'stock_unit' => 'un', 'active' => true]);
        $location = Location::query()->create(['name' => 'Fábrica Central', 'type' => 'production', 'active' => true]);
        $key = (string) Str::uuid();

        $previewResponse = $this->actingAs($user)->post(route('stock.opening.preview'), [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => '120',
            'operation_date' => '2026-08-17',
            'notes' => 'Contagem física real conferida pelo responsável.',
            'idempotency_key' => $key,
        ]);

        $previewResponse->assertOk()->assertSee('Confirmar estoque inicial')->assertSee('120');
        $this->assertDatabaseCount('stock_movements', 0);
        $token = $previewResponse->viewData('preview')['token'];

        $this->post(route('stock.opening.store'), ['preview_token' => $token])
            ->assertRedirect(route('stock.show', [$product, $location]));
        $this->post(route('stock.opening.store'), ['preview_token' => $token])
            ->assertRedirect(route('stock.show', [$product, $location]));

        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'type' => StockMovementType::OpeningBalance->value,
            'idempotency_key' => $key,
            'created_by' => $user->id,
            'reference_type' => 'opening_stock',
        ]);
        $movement = StockMovement::query()->sole();
        $this->assertSame('120.000000', $movement->quantity_delta);
        $this->assertSame('2026-08-17', $movement->operation_date->toDateString());
    }

    public function test_opening_stock_is_location_scoped_requires_location_and_cannot_be_repeated(): void
    {
        $user = User::factory()->create();
        $product = Product::query()->create(['name' => 'Costela com Queijo', 'stock_unit' => 'un', 'active' => true]);
        $factory = Location::query()->create(['name' => 'Fábrica Central', 'type' => 'production', 'active' => true]);
        $store = Location::query()->create(['name' => 'Unidade Ibirá', 'type' => 'store', 'active' => true]);
        $service = app(OpeningStockService::class);

        $service->record(['product_id' => $product->id, 'location_id' => $factory->id, 'quantity' => '10', 'operation_date' => '2026-08-17', 'notes' => 'Contagem real.', 'idempotency_key' => 'opening-factory'], $user);
        $service->record(['product_id' => $product->id, 'location_id' => $store->id, 'quantity' => '4', 'operation_date' => '2026-08-17', 'notes' => 'Contagem real.', 'idempotency_key' => 'opening-store'], $user);

        $this->assertEquals(10, StockMovement::query()->whereBelongsTo($factory)->sum('quantity_delta'));
        $this->assertEquals(4, StockMovement::query()->whereBelongsTo($store)->sum('quantity_delta'));
        $this->actingAs($user)->post(route('stock.opening.preview'), [
            'product_id' => $product->id, 'quantity' => '1', 'operation_date' => '2026-08-17', 'notes' => 'Teste de validação.', 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('location_id');

        $this->expectException(DomainException::class);
        $service->record(['product_id' => $product->id, 'location_id' => $factory->id, 'quantity' => '1', 'operation_date' => '2026-08-17', 'notes' => 'Nova tentativa.', 'idempotency_key' => 'second-opening'], $user);
    }

    public function test_common_user_cannot_access_opening_stock_or_readiness_pages(): void
    {
        $user = User::factory()->unprivileged()->create();

        $this->actingAs($user)->get(route('stock.opening.create'))->assertForbidden();
        $this->get(route('operations.readiness'))->assertForbidden();
    }

    public function test_agent_collects_missing_real_fields_previews_and_cancellation_writes_nothing(): void
    {
        $user = User::factory()->create();
        $product = Product::query()->create(['name' => 'Frango com Catupiry', 'stock_unit' => 'un', 'active' => true]);
        $location = Location::query()->create(['name' => 'Fábrica Central', 'type' => 'production', 'active' => true]);
        UserExternalIdentity::query()->create(['user_id' => $user->id, 'channel' => 'local-test', 'external_user_id' => 'opening-master', 'status' => 'approved', 'active' => true, 'structured_commands_allowed' => true]);

        $askDate = $this->agent('Coloque estoque inicial de 120 Frango com Catupiry na Fábrica Central.', 'opening-1');
        $this->assertStringContainsString('data real', $askDate->message);
        $askNotes = $this->agent('2026-08-17', 'opening-2');
        $this->assertStringContainsString('justificativa', $askNotes->message);
        $preview = $this->agent('Contagem física real conferida.', 'opening-3');
        $this->assertSame('confirmation', $preview->responseType);
        $this->assertStringContainsString($product->name, $preview->message);
        $this->assertStringContainsString($location->name, $preview->message);
        $this->assertDatabaseCount('stock_movements', 0);

        $cancelled = $this->agent('NÃO', 'opening-4');
        $this->assertSame('Operação cancelada.', $cancelled->message);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('pending_agent_actions', ['tool_name' => 'stock.opening_balance.record', 'status' => 'cancelled']);
        $this->assertDatabaseHas('agent_events', ['event_type' => 'confirmation_cancelled', 'tool_name' => 'stock.opening_balance.record']);
    }

    public function test_agent_does_not_invent_missing_base_unit_when_asked_to_register_an_ingredient(): void
    {
        $user = User::factory()->create();
        UserExternalIdentity::query()->create(['user_id' => $user->id, 'channel' => 'local-test', 'external_user_id' => 'opening-master', 'status' => 'approved', 'active' => true, 'structured_commands_allowed' => true]);

        $question = $this->agent('Cadastre muçarela.', 'ingredient-1');
        $this->assertStringContainsString('unidade-base', $question->message);
        $this->assertDatabaseCount('ingredients', 0);

        $preview = $this->agent('g', 'ingredient-2');
        $this->assertSame('confirmation', $preview->responseType);
        $this->assertStringContainsString('muçarela', $preview->message);
        $this->assertDatabaseCount('ingredients', 0);

        $this->agent('NÃO', 'ingredient-3');
        $this->assertDatabaseCount('ingredients', 0);
    }

    public function test_agent_only_records_opening_stock_after_explicit_confirmation(): void
    {
        $user = User::factory()->create();
        $product = Product::query()->create(['name' => 'Alcatra com Provolone', 'stock_unit' => 'un', 'active' => true]);
        $location = Location::query()->create(['name' => 'Fábrica Central', 'type' => 'production', 'active' => true]);
        UserExternalIdentity::query()->create(['user_id' => $user->id, 'channel' => 'local-test', 'external_user_id' => 'opening-master', 'status' => 'approved', 'active' => true, 'structured_commands_allowed' => true]);

        $this->agent('Coloque estoque inicial de 32 Alcatra com Provolone na Fábrica Central.', 'confirm-opening-1');
        $this->agent('2026-08-17', 'confirm-opening-2');
        $preview = $this->agent('Contagem física real conferida.', 'confirm-opening-3');
        $this->assertSame('confirmation', $preview->responseType);
        $this->assertDatabaseCount('stock_movements', 0);

        $confirmed = $this->agent('SIM', 'confirm-opening-4');
        $this->assertTrue($confirmed->success);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'location_id' => $location->id, 'type' => StockMovementType::OpeningBalance->value]);
        $this->assertDatabaseHas('pending_agent_actions', ['tool_name' => 'stock.opening_balance.record', 'status' => 'executed']);
        $this->assertDatabaseHas('agent_events', ['event_type' => 'confirmation_executed', 'tool_name' => 'stock.opening_balance.record']);
    }

    public function test_readiness_checklist_is_dynamic_and_does_not_create_operational_data(): void
    {
        $user = User::factory()->create();
        Product::query()->create(['name' => 'Produto Oficial', 'stock_unit' => 'un', 'active' => true]);
        Location::query()->create(['name' => 'Unidade Real', 'type' => 'store', 'active' => true]);

        $this->actingAs($user)->get(route('operations.readiness'))
            ->assertOk()
            ->assertSee('Preparação para operação')
            ->assertSee('0 / 1');

        $this->assertDatabaseCount('ingredients', 0);
        $this->assertDatabaseCount('preparations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    private function agent(string $text, string $messageId): mixed
    {
        return app(ErpAgentService::class)->handle(new AgentMessage('local-test', 'opening-master', $messageId, $text));
    }
}
