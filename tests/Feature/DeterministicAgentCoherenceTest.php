<?php

namespace Tests\Feature;

use App\Agent\AgentMessage;
use App\Agent\AgentToolExecutor;
use App\Agent\AgentToolRegistry;
use App\Agent\DeterministicCommandParser;
use App\Agent\ErpAgentService;
use App\Enums\ProductionStatus;
use App\Enums\StockTransferStatus;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductionRecord;
use App\Models\ProductStockPolicy;
use App\Models\PurchaseDocument;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\UserExternalIdentity;
use Database\Seeders\AuthorizationSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeterministicAgentCoherenceTest extends TestCase
{
    use RefreshDatabase;

    private Location $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->factory = Location::query()->create(['name' => 'Fábrica Ibirá', 'type' => 'production', 'active' => true]);
    }

    public function test_every_fixed_menu_command_has_a_valid_destination(): void
    {
        $parser = app(DeterministicCommandParser::class);
        $registry = app(AgentToolRegistry::class);

        foreach (['ESTOQUE', 'PRODUÇÃO', 'PRODUCAO', 'FINANCEIRO', 'COMPRAS', 'TRANSFERÊNCIAS', 'RELATÓRIO OPERACIONAL'] as $command) {
            $intent = $parser->parse($command);
            $this->assertNotNull($intent, $command);
            $this->assertTrue(isset($intent['submenu']) || $registry->get($intent['tool']) !== null, $command);
        }

        foreach (['PRODUÇÃO HOJE', 'PRODUCAO HOJE', 'PRODUÇÃO SUGERIDA', 'DOCUMENTOS RECENTES', 'TRANSFERÊNCIAS RECENTES', 'TRANSFERÊNCIAS EM TRÂNSITO', 'PENDENTES DE RECEBIMENTO', 'FINANCEIRO HOJE', 'CONTAS A PAGAR'] as $command) {
            $intent = $parser->parse($command);
            $this->assertNotNull($intent, $command);
            $this->assertNotNull($registry->get($intent['tool']), $command);
        }
    }

    public function test_main_menu_and_submenus_are_permission_driven_and_executable(): void
    {
        $user = $this->known('producer', ['production.view', 'production.create', 'production_requirements.view']);

        $menu = $this->agent('producer', 'MENU', 'menu-1');
        $this->assertSame(['PRODUÇÃO'], collect($menu->options)->pluck('command')->all());

        $production = $this->agent('producer', 'PRODUÇÃO', 'menu-2');
        $this->assertSame('menu', $production->responseType);
        $this->assertSame(['PRODUÇÃO HOJE', null, 'PRODUÇÃO SUGERIDA'], collect($production->options)->pluck('command')->all());
        $this->assertDatabaseHas('agent_events', ['user_id' => $user->id, 'event_type' => 'submenu_opened']);
        $this->assertDatabaseMissing('agent_events', ['user_id' => $user->id, 'event_type' => 'ai_called']);
    }

    public function test_production_today_and_suggestions_reuse_official_queries_with_location_scope(): void
    {
        $user = $this->known('production-query', ['production.view', 'production_requirements.view']);
        $product = Product::query()->create(['name' => 'Frango', 'stock_unit' => 'un', 'active' => true]);
        ProductionRecord::query()->create(['product_id' => $product->id, 'location_id' => $this->factory->id, 'planned_quantity' => '40', 'operation_date' => now()->toDateString(), 'status' => ProductionStatus::Planned, 'idempotency_key' => (string) Str::uuid(), 'created_by' => $user->id]);
        ProductStockPolicy::query()->create(['product_id' => $product->id, 'location_id' => $this->factory->id, 'target_quantity' => '100', 'production_priority' => 1, 'active' => true, 'updated_by' => $user->id]);

        $today = $this->agent('production-query', 'PRODUÇÃO HOJE', 'production-today');
        $this->assertStringContainsString('Frango', $today->message);
        $this->assertStringContainsString('Planejada', $today->message);

        $suggestions = $this->agent('production-query', 'PRODUÇÃO SUGERIDA', 'production-suggested');
        $this->assertStringContainsString('Produzir: 60 un', $suggestions->message);
    }

    public function test_deterministic_production_plan_requires_preview_confirmation_and_is_idempotent(): void
    {
        $this->known('planner', ['production.create']);
        Product::query()->create(['name' => 'Costela', 'stock_unit' => 'un', 'active' => true]);

        $preview = $this->agent('planner', 'PRODUZIMOS 20 Costela', 'plan-1');
        $this->assertSame('confirmation', $preview->responseType);
        $this->assertDatabaseCount('production_records', 0);

        $this->agent('planner', 'SIM', 'plan-2');
        $this->agent('planner', 'SIM', 'plan-3');
        $this->assertDatabaseCount('production_records', 1);
    }

    public function test_stock_command_formats_official_balance_and_denies_another_location(): void
    {
        $user = $this->known('stock-user', ['stock.view']);
        Product::query()->create(['name' => 'Frango', 'stock_unit' => 'un', 'active' => true]);

        $response = $this->agent('stock-user', 'ESTOQUE', 'stock-1');
        $this->assertStringContainsString('Fábrica Ibirá', $response->message);
        $this->assertStringContainsString('Frango: 0 un', $response->message);

        $other = Location::query()->create(['name' => 'Loja Catanduva', 'type' => 'store', 'active' => true]);
        $this->expectException(AuthorizationException::class);
        app(AgentToolExecutor::class)->execute('stock.positions.list', ['location_id' => $other->id], $user);
    }

    public function test_purchases_menu_documents_and_items_respect_permissions_and_location(): void
    {
        $this->known('buyer', ['purchases.view']);
        $document = PurchaseDocument::query()->create(['document_type' => 'invoice', 'document_number' => 'NF-10', 'issue_date' => now(), 'total_amount' => '100', 'location_id' => $this->factory->id, 'source' => 'web', 'idempotency_key' => (string) Str::uuid()]);
        $document->items()->create(['description' => 'Farinha', 'quantity' => '2', 'unit' => 'kg', 'unit_price' => '50', 'total_price' => '100']);

        $submenu = $this->agent('buyer', 'COMPRAS', 'buy-1');
        $this->assertSame('DOCUMENTOS RECENTES', $submenu->options[0]['command']);
        $this->assertStringContainsString('NF-10', $this->agent('buyer', 'DOCUMENTOS RECENTES', 'buy-2')->message);
        $this->assertStringContainsString('Farinha', $this->agent('buyer', 'ITENS DOCUMENTO '.$document->id, 'buy-3')->message);

        $this->known('not-buyer', []);
        $denied = $this->agent('not-buyer', 'COMPRAS', 'buy-4');
        $this->assertFalse($denied->success);
    }

    public function test_transfer_and_operational_queries_are_read_only_and_location_scoped(): void
    {
        $this->known('operator', ['transfers.view', 'reports.view']);
        $destination = Location::query()->create(['name' => 'Loja', 'type' => 'store', 'active' => true]);
        $product = Product::query()->create(['name' => 'Frango', 'stock_unit' => 'un', 'active' => true]);
        $transfer = StockTransfer::query()->create(['source_location_id' => $this->factory->id, 'destination_location_id' => $destination->id, 'status' => StockTransferStatus::InTransit, 'operation_date' => now(), 'idempotency_key' => (string) Str::uuid()]);
        $transfer->items()->create(['product_id' => $product->id, 'quantity_sent' => '10']);

        $transfers = $this->agent('operator', 'TRANSFERÊNCIAS EM TRÂNSITO', 'transfer-1');
        $this->assertStringContainsString('Fábrica Ibirá → Loja', $transfers->message);
        $this->assertStringContainsString('Nenhum movimento', $this->agent('operator', 'RELATÓRIO OPERACIONAL', 'report-1')->message);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_write_tool_cannot_execute_without_confirmation_and_unknown_command_fails_safely(): void
    {
        $user = $this->known('secure', ['production.create']);
        $product = Product::query()->create(['name' => 'Queijo', 'stock_unit' => 'un', 'active' => true]);
        $payload = ['product_id' => $product->id, 'location_id' => $this->factory->id, 'planned_quantity' => '10', 'operation_date' => now()->toDateString(), 'idempotency_key' => (string) Str::uuid()];

        $this->expectException(DomainException::class);
        try {
            app(AgentToolExecutor::class)->execute('production.plan', $payload, $user);
        } finally {
            $this->assertDatabaseCount('production_records', 0);
            $unknown = $this->agent('secure', 'COMANDO INEXISTENTE', 'unknown-1');
            $this->assertSame('command_not_understood', $unknown->errorCode);
        }
    }

    private function known(string $external, array $permissions): User
    {
        $user = User::factory()->unprivileged()->create(['name' => $external]);
        foreach (array_unique([...$permissions, 'agent.text.use']) as $permission) {
            $user->permissions()->attach(Permission::query()->where('name', $permission)->firstOrFail(), ['allowed' => true]);
        }
        $user->locations()->attach($this->factory);
        UserExternalIdentity::query()->create(['user_id' => $user->id, 'channel' => 'local-test', 'external_user_id' => $external, 'status' => 'approved', 'active' => true, 'structured_commands_allowed' => true]);

        return $user;
    }

    private function agent(string $external, string $text, string $messageId)
    {
        return app(ErpAgentService::class)->handle(new AgentMessage('local-test', $external, $messageId, $text));
    }
}
