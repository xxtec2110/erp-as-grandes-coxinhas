<?php

namespace Tests\Feature;

use App\Agent\AgentMessage;
use App\Agent\ErpAgentResponse;
use App\Agent\ErpAgentService;
use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\CreatePayableService;
use App\Services\IngredientPriceService;
use App\Services\IngredientStockService;
use App\Services\ProductRecipeService;
use App\Services\StockBalanceService;
use App\Services\StockMovementService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpAgentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Location $catanduva;

    protected Location $ibira;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->catanduva = Location::query()->create(['name' => 'Catanduva', 'type' => 'store', 'active' => true]);
        $this->ibira = Location::query()->create(['name' => 'Fábrica Ibirá', 'type' => 'production', 'active' => true]);
    }

    public function test_unknown_identity_receives_standard_unauthorized_response(): void
    {
        $response = $this->agent()->handle($this->message('unknown', 'OI'));
        $this->assertInstanceOf(ErpAgentResponse::class, $response);
        $this->assertFalse($response->success);
        $this->assertSame('unauthorized', $response->responseType);
        $this->assertSame('unknown_identity', $response->errorCode);
        $this->assertDatabaseHas('user_external_identities', [
            'channel' => 'local-test',
            'external_user_id' => 'unknown',
            'status' => 'pending',
            'active' => false,
        ]);
    }

    public function test_known_user_receives_menu_filtered_by_permission(): void
    {
        $user = $this->known('operator', ['stock.view']);
        $response = $this->agent()->handle($this->message('operator', 'MENU'));
        $labels = collect($response->options)->pluck('label');
        $this->assertTrue($response->success);
        $this->assertContains('Consultar estoque', $labels);
        $this->assertNotContains('Financeiro', $labels);
        $this->assertStringContainsString($user->name, $response->message);
    }

    public function test_deterministic_stock_query_asks_unit_then_continues_same_action(): void
    {
        $user = $this->known('multi', ['stock.view'], [$this->catanduva, $this->ibira]);
        $product = Product::query()->create(['name' => 'Frango', 'stock_unit' => 'un', 'active' => true]);
        app(StockMovementService::class)->record(new RecordStockMovementData($product->id, $this->catanduva->id, StockMovementType::OpeningBalance, '120', now()->toDateString(), 'stock-agent'));
        $ask = $this->agent()->handle($this->message('multi', 'ESTOQUE', 'stock-1'));
        $this->assertSame('menu', $ask->responseType);
        $this->assertSame('De qual unidade?', $ask->message);
        $answer = $this->agent()->handle($this->message('multi', 'Quero consultar na unidade Catanduva.', 'stock-2'));
        $this->assertStringContainsString('Frango: 120', $answer->message);
        $this->assertDatabaseHas('pending_agent_actions', ['user_id' => $user->id, 'status' => 'executed']);
    }

    public function test_location_isolation_rejects_other_unit_from_fake_provider(): void
    {
        $this->known('cat', ['stock.view'], [$this->catanduva]);
        $response = $this->agent()->handle($this->message('cat', 'consulta', 'scope-1', ['tool' => 'stock.positions.list', 'arguments' => ['location_id' => $this->ibira->id]]));
        $this->assertFalse($response->success);
        $this->assertSame('forbidden', $response->errorCode);
    }

    public function test_fake_provider_selects_write_tool_and_requires_confirmation(): void
    {
        $user = $this->known('admin-fin', ['finance.payables.create'], [$this->catanduva]);
        $args = ['description' => 'Aluguel', 'location_id' => $this->catanduva->id, 'expected_amount' => '3500', 'competency_date' => now()->toDateString(), 'due_date' => now()->addDays(5)->toDateString(), 'recurring' => false, 'recurrence_rule' => null, 'supplier_id' => null, 'cost_center_id' => null, 'finance_category_id' => null, 'notes' => null];
        $preview = $this->agent()->handle($this->message('admin-fin', 'registre', 'payable-1', ['tool' => 'finance.payables.create', 'arguments' => $args]));
        $this->assertSame('confirmation', $preview->responseType);
        $this->assertDatabaseCount('payables', 0);
        $confirmed = $this->agent()->handle($this->message('admin-fin', 'SIM', 'payable-2'));
        $this->assertTrue($confirmed->success);
        $this->assertDatabaseCount('payables', 1);
        $this->assertDatabaseHas('finance_audits', ['user_id' => $user->id, 'channel' => 'agent']);
    }

    public function test_no_cancels_pending_write(): void
    {
        $this->known('cancel', ['finance.payables.create'], [$this->catanduva]);
        $args = ['description' => 'Internet', 'location_id' => $this->catanduva->id, 'expected_amount' => '100', 'competency_date' => now()->toDateString(), 'due_date' => now()->toDateString()];
        $this->agent()->handle($this->message('cancel', 'registre', 'cancel-1', ['tool' => 'finance.payables.create', 'arguments' => $args]));
        $response = $this->agent()->handle($this->message('cancel', 'NÃO', 'cancel-2'));
        $this->assertSame('Operação cancelada.', $response->message);
        $this->assertDatabaseCount('payables', 0);
    }

    public function test_duplicate_external_message_returns_same_response_and_does_not_repeat_events_or_write(): void
    {
        $this->known('duplicate', ['finance.payables.create'], [$this->catanduva]);
        $args = ['description' => 'Energia', 'location_id' => $this->catanduva->id, 'expected_amount' => '200', 'competency_date' => now()->toDateString(), 'due_date' => now()->toDateString()];
        $message = $this->message('duplicate', 'registre', 'duplicate-1', ['tool' => 'finance.payables.create', 'arguments' => $args]);
        $first = $this->agent()->handle($message);
        $second = $this->agent()->handle($message);
        $this->assertSame($first->message, $second->message);
        $this->assertDatabaseCount('pending_agent_actions', 1);
        $this->assertDatabaseHas('agent_events', ['event_type' => 'duplicate_blocked']);
    }

    public function test_production_via_fake_provider_uses_official_service_after_confirmation(): void
    {
        $this->known('producer', ['production.orders.create', 'production.orders.complete'], [$this->ibira]);
        $supplier = Supplier::query()->create(['name' => 'Fornecedor de produção', 'active' => true]);
        $ingredientX = Ingredient::query()->create(['name' => 'Insumo X', 'base_unit' => 'un', 'active' => true]);
        $ingredientY = Ingredient::query()->create(['name' => 'Insumo Y', 'base_unit' => 'un', 'active' => true]);
        foreach ([$ingredientX, $ingredientY] as $ingredient) {
            app(IngredientPriceService::class)->record($ingredient, ['supplier_id' => $supplier->id, 'purchase_quantity' => '1', 'purchase_unit' => 'un', 'price_paid' => '1', 'effective_date' => now()->toDateString()]);
        }
        $product = Product::query()->create(['name' => 'Costela', 'stock_unit' => 'un', 'active' => true]);
        app(ProductRecipeService::class)->save($product, ['yield_quantity' => '1', 'technical_loss_percentage' => '0', 'packaging_cost' => '0', 'ingredients' => [
            ['ingredient_id' => $ingredientX->id, 'quantity' => '2', 'unit' => 'un'],
            ['ingredient_id' => $ingredientY->id, 'quantity' => '1', 'unit' => 'un'],
        ]]);
        app(IngredientStockService::class)->record(['ingredient_id' => $ingredientX->id, 'location_id' => $this->ibira->id, 'type' => 'positive_adjustment', 'quantity_delta' => '30', 'operation_date' => now()->toDateString(), 'idempotency_key' => 'production-x']);
        app(IngredientStockService::class)->record(['ingredient_id' => $ingredientY->id, 'location_id' => $this->ibira->id, 'type' => 'positive_adjustment', 'quantity_delta' => '20', 'operation_date' => now()->toDateString(), 'idempotency_key' => 'production-y']);

        $preview = $this->agent()->handle($this->message('producer', 'produzimos 10 Costela', 'production-1'));
        $this->assertSame('confirmation', $preview->responseType);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('ingredient_stock_movements', 2);
        $this->agent()->handle($this->message('producer', 'SIM', 'production-2'));
        $this->agent()->handle($this->message('producer', 'SIM', 'production-3'));

        $this->assertSame('10.000000', app(IngredientStockService::class)->balance($ingredientX->id, $this->ibira->id));
        $this->assertSame('10.000000', app(IngredientStockService::class)->balance($ingredientY->id, $this->ibira->id));
        $this->assertSame('10.000000', app(StockBalanceService::class)->balance($product, $this->ibira));
        $this->assertDatabaseCount('production_orders', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('ingredient_stock_movements', 4);
        $this->assertDatabaseCount('production_records', 0);
    }

    public function test_ambiguous_product_resolution_preserves_all_photo_items(): void
    {
        $this->known('photo-producer', ['production.orders.create'], [$this->ibira]);
        $frango = Product::query()->create(['name' => 'Frango', 'stock_unit' => 'un', 'active' => true]);
        $catupiry = Product::query()->create(['name' => 'Frango com Catupiry', 'stock_unit' => 'un', 'active' => true]);
        $costela = Product::query()->create(['name' => 'Costela', 'stock_unit' => 'un', 'active' => true]);
        $items = [
            ['product_name' => 'Frango', 'planned_quantity' => '80'],
            ['product_id' => $costela->id, 'product_name' => 'Costela', 'planned_quantity' => '40'],
        ];
        $question = $this->agent()->handle($this->message('photo-producer', 'foto', 'photo-1', ['tool' => 'production.orders.plan', 'arguments' => ['location_id' => $this->ibira->id, 'production_date' => now()->toDateString(), 'items' => $items], 'missing_fields' => ['product_id']]));
        $this->assertStringContainsString('mais de uma possibilidade', $question->message);

        $preview = $this->agent()->handle($this->message('photo-producer', '2', 'photo-2'));

        $this->assertSame('confirmation', $preview->responseType);
        $this->assertStringContainsString('Frango com Catupiry: 80', $preview->message);
        $this->assertStringContainsString('Costela: 40', $preview->message);
        $this->assertDatabaseHas('pending_agent_actions', ['tool_name' => 'production.orders.plan', 'status' => 'pending']);
    }

    public function test_finance_query_is_deterministic_and_does_not_call_fake_ai(): void
    {
        $user = $this->known('finance', ['finance.payables.view'], [$this->catanduva]);
        app(CreatePayableService::class)->create(['supplier_id' => null, 'description' => 'Água', 'location_id' => $this->catanduva->id, 'cost_center_id' => null, 'finance_category_id' => null, 'expected_amount' => '80', 'competency_date' => now()->toDateString(), 'due_date' => now()->addDay()->toDateString(), 'recurring' => false, 'recurrence_rule' => null, 'notes' => null, 'idempotency_key' => 'finance-query'], User::factory()->create());
        $response = $this->agent()->handle($this->message('finance', 'CONTAS A PAGAR', 'finance-1'));
        $this->assertStringContainsString('R$ 80,00', $response->message);
        $this->assertDatabaseHas('agent_events', ['user_id' => $user->id, 'event_type' => 'deterministic_command']);
        $this->assertDatabaseMissing('agent_events', ['user_id' => $user->id, 'event_type' => 'ai_called']);
    }

    public function test_users_and_conversations_are_isolated(): void
    {
        $first = $this->known('first', ['stock.view'], [$this->catanduva]);
        $second = $this->known('second', ['stock.view'], [$this->ibira]);
        $this->agent()->handle($this->message('first', 'ESTOQUE', 'isolation-1'));
        $this->agent()->handle($this->message('second', 'ESTOQUE', 'isolation-2'));
        $this->assertDatabaseHas('agent_conversations', ['user_id' => $first->id, 'external_conversation_id' => 'first']);
        $this->assertDatabaseHas('agent_conversations', ['user_id' => $second->id, 'external_conversation_id' => 'second']);
    }

    private function agent(): ErpAgentService
    {
        return app(ErpAgentService::class);
    }

    private function message(string $external, string $text, ?string $id = null, ?array $intent = null): AgentMessage
    {
        return new AgentMessage('local-test', $external, $id ?? uniqid('message-', true), $text, metadata: $intent === null ? [] : ['fake_intent' => $intent]);
    }

    private function known(string $external, array $permissions, array $locations = []): User
    {
        $user = User::factory()->unprivileged()->create(['name' => ucfirst($external)]);
        $permissions[] = 'agent.text.use';
        foreach ($permissions as $name) {
            $user->permissions()->attach(Permission::query()->where('name', $name)->firstOrFail(), ['allowed' => true]);
        } if ($locations !== []) {
            $user->locations()->attach(collect($locations)->pluck('id'));
        } UserExternalIdentity::query()->create(['user_id' => $user->id, 'channel' => 'local-test', 'external_user_id' => $external, 'active' => true]);

        return $user;
    }
}
