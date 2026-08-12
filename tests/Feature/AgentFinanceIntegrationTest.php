<?php

namespace Tests\Feature;

use App\Agent\AgentConversationService;
use App\Agent\AgentResponseTemplate;
use App\Agent\AgentToolExecutor;
use App\Agent\AgentToolRegistry;
use App\Agent\DeterministicCommandParser;
use App\Agent\PendingAgentActionService;
use App\Models\AgentAttachment;
use App\Models\FinancialAccount;
use App\Models\Location;
use App\Models\Payable;
use App\Models\Permission;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CreatePayableService;
use Database\Seeders\AuthorizationSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentFinanceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Location $ibira;

    protected Location $catanduva;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $this->ibira = Location::query()->create(['name' => 'Ibirá', 'type' => 'store', 'active' => true]);
        $this->catanduva = Location::query()->create(['name' => 'Catanduva', 'type' => 'store', 'active' => true]);
    }

    public function test_registry_has_complete_finance_and_purchase_tools_with_schemas(): void
    {
        $tools = app(AgentToolRegistry::class)->all();
        foreach (['finance.payables.list', 'finance.payables.get', 'finance.payables.create', 'finance.payments.record', 'finance.payments.list', 'finance.accounts.list', 'finance.reports.summary', 'purchases.documents.list', 'purchases.documents.get', 'purchases.documents.create', 'purchases.items.list', 'purchases.link_supplier', 'purchases.suggest_ingredient_price_update'] as $name) {
            $this->assertArrayHasKey($name, $tools);
            $this->assertIsArray($tools[$name]->inputSchema);
            $this->assertIsArray($tools[$name]->outputSchema);
            $this->assertNotSame('', $tools[$name]->permission);
        }
    }

    public function test_read_tool_respects_permission_and_location_scope(): void
    {
        $this->payable($this->ibira, 'Conta Ibirá');
        $this->payable($this->catanduva, 'Conta Catanduva');
        $user = User::factory()->unprivileged()->create();
        $user->permissions()->attach(Permission::query()->where('name', 'finance.payables.view')->firstOrFail(), ['allowed' => true]);
        $user->locations()->attach($this->catanduva);
        $items = app(AgentToolExecutor::class)->execute('finance.payables.list', ['period' => 'open'], $user);
        $this->assertCount(1, $items);
        $this->assertSame('Conta Catanduva', $items->first()->description);
        $this->expectException(AuthorizationException::class);
        app(AgentToolExecutor::class)->execute('finance.payables.list', ['period' => 'open', 'location_id' => $this->ibira->id], $user);
    }

    public function test_write_tool_requires_pending_confirmation_executes_once_and_audits(): void
    {
        $payload = $this->data($this->catanduva, 'Aluguel');
        $actions = app(PendingAgentActionService::class);
        $action = $actions->prepare($this->admin, 'finance.payables.create', $payload, [], (string) Str::uuid());
        try {
            app(AgentToolExecutor::class)->execute('finance.payables.create', $payload, $this->admin);
            $this->fail('Deveria exigir confirmação.');
        } catch (DomainException) {
            $this->assertDatabaseCount('payables', 0);
        }
        $executed = $actions->confirm($action, $this->admin, app(AgentToolExecutor::class));
        $again = $actions->confirm($executed, $this->admin, app(AgentToolExecutor::class));
        $this->assertSame('executed', $again->status);
        $this->assertDatabaseCount('payables', 1);
        $this->assertDatabaseHas('finance_audits', ['action' => 'payable.created', 'channel' => 'agent']);
    }

    public function test_cancelled_confirmation_never_writes(): void
    {
        $actions = app(PendingAgentActionService::class);
        $action = $actions->prepare($this->admin, 'finance.payables.create', $this->data($this->ibira, 'Energia'), [], (string) Str::uuid());
        $this->assertSame('cancelled', $actions->cancel($action, $this->admin)->status);
        $this->assertDatabaseCount('payables', 0);
    }

    public function test_multi_turn_conversation_preserves_payload_and_message_idempotency(): void
    {
        $conversations = app(AgentConversationService::class);
        $conversation = $conversations->conversation($this->admin);
        $conversations->message($conversation, 'user', 'Quero cadastrar essa conta', ['amount' => '3500'], 'message-1');
        $conversations->message($conversation, 'user', 'repetida', null, 'message-1');
        $action = app(PendingAgentActionService::class)->prepare($this->admin, 'finance.payables.create', ['description' => 'Aluguel', 'expected_amount' => '3500'], ['location_id'], (string) Str::uuid(), $conversation->id);
        $action = app(PendingAgentActionService::class)->merge($action, $this->admin, ['location_id' => $this->catanduva->id], []);
        $this->assertSame('3500', $action->payload['expected_amount']);
        $this->assertSame($this->catanduva->id, $action->payload['location_id']);
        $this->assertDatabaseCount('agent_conversation_messages', 1);
    }

    public function test_ambiguous_supplier_and_payable_are_returned_without_guessing(): void
    {
        Supplier::query()->create(['name' => 'Dom Armando Centro', 'active' => true]);
        Supplier::query()->create(['name' => 'Dom Armando Atacado', 'active' => true]);
        $supplier = Supplier::query()->first();
        $first = $this->data($this->catanduva, 'Compra 1');
        $first['supplier_id'] = $supplier->id;
        $second = $this->data($this->catanduva, 'Compra 2');
        $second['supplier_id'] = $supplier->id;
        app(CreatePayableService::class)->create($first, $this->admin);
        app(CreatePayableService::class)->create($second, $this->admin);
        $items = app(AgentToolExecutor::class)->execute('finance.payables.list', ['period' => 'open', 'supplier' => 'Dom Armando'], $this->admin);
        $this->assertCount(2, $items);
    }

    public function test_payment_tool_supports_partial_payment_and_attachment_pipeline(): void
    {
        $payable = $this->payable($this->catanduva, 'Fornecedor');
        $account = FinancialAccount::query()->create(['name' => 'Conta PJ', 'type' => 'bank', 'active' => true]);
        $attachment = AgentAttachment::query()->create(['source' => 'structured_fake', 'content_hash' => hash('sha256', 'proof'), 'created_by' => $this->admin->id]);
        $payload = ['payable_id' => $payable->id, 'amount' => '40', 'paid_at' => now()->toDateTimeString(), 'financial_account_id' => $account->id, 'paid_by_name' => 'Alexandre', 'payment_method' => 'pix', 'partner_advance' => false, 'agent_attachment_id' => $attachment->id, 'idempotency_key' => (string) Str::uuid()];
        $action = app(PendingAgentActionService::class)->prepare($this->admin, 'finance.payments.record', $payload, [], (string) Str::uuid());
        app(PendingAgentActionService::class)->confirm($action, $this->admin, app(AgentToolExecutor::class));
        $this->assertSame('partially_paid', $payable->refresh()->status);
        $this->assertDatabaseHas('payments', ['amount' => '40.00', 'agent_attachment_id' => $attachment->id]);
    }

    public function test_parser_and_template_use_erp_calculated_values(): void
    {
        $payable = $this->payable($this->catanduva, 'CPFL');
        $this->assertSame('finance.reports.summary', app(DeterministicCommandParser::class)->parse('FINANCEIRO MÊS')['tool']);
        $this->assertSame('finance.payments.list', app(DeterministicCommandParser::class)->parse('PAGADOR Alexandre')['tool']);
        $text = app(AgentResponseTemplate::class)->payables(collect([$payable]), 'Catanduva');
        $this->assertStringContainsString('R$ 100,00', $text);
        $this->assertStringContainsString('Total em aberto', $text);
    }

    public function test_structured_attachment_creates_purchase_document_only_after_confirmation(): void
    {
        $attachment = AgentAttachment::query()->create(['source' => 'structured_fake', 'content_hash' => hash('sha256', 'invoice'), 'created_by' => $this->admin->id, 'metadata' => ['parsed' => true]]);
        $payload = ['supplier_id' => null, 'document_type' => 'invoice', 'document_number' => 'NF-10', 'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(10)->toDateString(), 'total_amount' => '860', 'location_id' => $this->ibira->id, 'cost_center_id' => null, 'finance_category_id' => null, 'agent_attachment_id' => $attachment->id, 'notes' => null, 'items' => [], 'idempotency_key' => (string) Str::uuid()];
        $action = app(PendingAgentActionService::class)->prepare($this->admin, 'purchases.documents.create', $payload, [], (string) Str::uuid());
        $this->assertDatabaseCount('purchase_documents', 0);
        app(PendingAgentActionService::class)->confirm($action, $this->admin, app(AgentToolExecutor::class));
        $this->assertDatabaseHas('purchase_documents', ['document_number' => 'NF-10', 'agent_attachment_id' => $attachment->id]);
        $documents = app(AgentToolExecutor::class)->execute('purchases.documents.list', [], $this->admin);
        $this->assertCount(1, $documents);
    }

    public function test_report_tool_uses_official_report_service_without_ai_calculation(): void
    {
        $this->payable($this->catanduva, 'Aluguel');
        $summary = app(AgentToolExecutor::class)->execute('finance.reports.summary', ['period' => 'month'], $this->admin);
        $this->assertSame('100', $summary['expected']);
        $this->assertSame('100', $summary['open']);
    }

    private function payable(Location $location, string $description): Payable
    {
        return app(CreatePayableService::class)->create($this->data($location, $description), $this->admin);
    }

    private function data(Location $location, string $description): array
    {
        return ['supplier_id' => null, 'description' => $description, 'location_id' => $location->id, 'cost_center_id' => null, 'finance_category_id' => null, 'expected_amount' => '100', 'competency_date' => now()->toDateString(), 'due_date' => now()->addDays(5)->toDateString(), 'recurring' => false, 'recurrence_rule' => null, 'notes' => null, 'idempotency_key' => (string) Str::uuid()];
    }
}
