<?php

namespace Tests\Feature;

use App\Agent\AgentMessage;
use App\Agent\AiInterpretation;
use App\Agent\AiProviderInterface;
use App\Agent\ErpAgentService;
use App\Models\AgentAttachment;
use App\Models\Location;
use App\Models\PendingAgentAction;
use App\Models\Permission;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\AiInterpretationService;
use App\Services\SupplierMatchService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class AiInterpretationPipelineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        Storage::fake('local');
        $this->location = Location::query()->create(['name' => 'Catanduva', 'type' => 'store', 'active' => true]);
        $this->user = User::factory()->unprivileged()->create();
        $this->user->locations()->sync([$this->location->id]);
    }

    public function test_free_text_write_creates_preview_but_does_not_write_before_confirmation(): void
    {
        $this->identity('text', ['agent.text.use', 'agent.free_chat.use', 'finance.payments.create'], ['free_chat_allowed' => true]);
        $response = app(ErpAgentService::class)->handle(new AgentMessage('local-test', 'text', 'text-pay-1', 'Paguei 1250 para fornecedor X.', metadata: ['fake_intent' => [
            'tool' => 'finance.payments.record', 'fields' => ['amount' => '1250', 'paid_at' => '2026-08-13'], 'missing_fields' => ['payable_id', 'financial_account_id', 'payment_method'],
        ]]));

        $this->assertTrue($response->success);
        $this->assertDatabaseCount('pending_agent_actions', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payable_preview_normalizes_legacy_amount_to_expected_amount(): void
    {
        $this->identity('payable-text', ['agent.text.use', 'agent.free_chat.use', 'finance.payables.create'], ['free_chat_allowed' => true]);
        $response = app(ErpAgentService::class)->handle(new AgentMessage('local-test', 'payable-text', 'payable-preview-1', 'Registre a conta.', metadata: ['fake_intent' => [
            'tool' => 'finance.payables.create',
            'fields' => ['description' => 'Teste', 'amount' => '10', 'location_id' => $this->location->id, 'competency_date' => '2026-08-15', 'due_date' => '2026-08-15'],
            'missing_fields' => ['location_id'],
        ]]));

        $this->assertSame('confirmation', $response->responseType);
        $this->assertDatabaseHas('pending_agent_actions', ['tool_name' => 'finance.payables.create', 'status' => 'pending']);
        $this->assertSame('10', (string) PendingAgentAction::query()->firstOrFail()->payload['expected_amount']);
        $this->assertDatabaseHas('agent_usage_costs', ['location_id' => $this->location->id]);
        $this->assertDatabaseCount('payables', 0);
    }

    public function test_document_creates_purchase_preview_and_never_updates_ingredient_prices(): void
    {
        $this->identity('document', ['agent.document.use', 'purchases.create'], ['document_allowed' => true]);
        $attachment = $this->attachment('purchase.pdf', 'application/pdf', '%PDF-test');
        $response = app(ErpAgentService::class)->handle(new AgentMessage('local-test', 'document', 'doc-1', null, 'document', [$attachment->id], metadata: ['fake_intent' => [
            'tool' => 'purchases.documents.create', 'document_type' => 'purchase_document', 'fields' => ['document_type' => 'invoice', 'issue_date' => '2026-08-13', 'total_amount' => '220.00', 'location_id' => $this->location->id, 'items' => [['description' => 'Muçarela', 'quantity' => '5', 'unit_price' => '44']]],
        ]]));

        $this->assertSame('confirmation', $response->responseType);
        $this->assertDatabaseCount('pending_agent_actions', 1);
        $this->assertDatabaseCount('purchase_documents', 0);
        $this->assertDatabaseCount('ingredient_prices', 0);
    }

    public function test_same_attachment_reuses_scoped_interpretation_cache(): void
    {
        $this->grant('agent.document.use');
        $attachment = $this->attachment('boleto.pdf', 'application/pdf', '%PDF-cache');
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('interpret')->once()->andReturn(AiInterpretation::fromArray(['intent' => 'boleto', 'tool' => null, 'confidence' => 0.9, 'fields' => ['amount' => '50'], 'missing_fields' => [], 'source_type' => 'document', 'document_type' => 'boleto', 'summary' => 'Boleto de teste.']));
        $this->app->instance(AiProviderInterface::class, $provider);
        $service = app(AiInterpretationService::class);
        $message = new AgentMessage('local-test', 'cache', 'cache-1', null, 'document', [$attachment->id]);

        $first = $service->interpret($message, [], $this->user);
        $second = $service->interpret($message, [], $this->user);

        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertTrue($second->usage['cached']);
        $this->assertSame('interpreted', $attachment->refresh()->processing_status);
    }

    public function test_unknown_tool_is_rejected_before_registry_execution(): void
    {
        $this->identity('unknown', ['agent.text.use', 'agent.free_chat.use'], ['free_chat_allowed' => true]);
        $response = app(ErpAgentService::class)->handle(new AgentMessage('local-test', 'unknown', 'unknown-1', 'faça algo', metadata: ['fake_intent' => ['tool' => 'system.destroy', 'fields' => []]]));

        $this->assertSame('validation_error', $response->errorCode);
        $this->assertDatabaseCount('pending_agent_actions', 0);
    }

    public function test_supplier_matching_is_exact_only_and_ambiguity_is_not_selected(): void
    {
        $exact = Supplier::query()->create(['name' => 'Dom Armando', 'active' => true]);
        Supplier::query()->create(['name' => 'Alimentos Ibirá', 'active' => true]);
        Supplier::query()->create(['name' => 'Alimentos Ibiraense', 'active' => true]);
        $service = app(SupplierMatchService::class);

        $this->assertSame($exact->id, $service->match('DOM  ARMANDO')['supplier_id']);
        $ambiguous = $service->match('Alimentos Ibir');
        $this->assertSame('ambiguous', $ambiguous['status']);
        $this->assertNull($ambiguous['supplier_id']);
    }

    private function identity(string $externalId, array $permissions, array $flags): void
    {
        foreach ($permissions as $permission) {
            $this->grant($permission);
        }
        UserExternalIdentity::query()->create(['user_id' => $this->user->id, 'channel' => 'local-test', 'external_user_id' => $externalId, 'status' => 'approved', 'active' => true, 'structured_commands_allowed' => true, ...$flags]);
    }

    private function grant(string $permission): void
    {
        $this->user->permissions()->syncWithoutDetaching([Permission::query()->where('name', $permission)->firstOrFail()->id => ['allowed' => true]]);
    }

    private function attachment(string $name, string $mime, string $contents): AgentAttachment
    {
        $path = 'agent-attachments/test/'.$name;
        Storage::disk('local')->put($path, $contents);

        return AgentAttachment::query()->create(['source' => 'web_agent', 'content_hash' => hash('sha256', $contents), 'disk' => 'local', 'path' => $path, 'original_name' => $name, 'mime_type' => $mime, 'size' => strlen($contents), 'processing_status' => 'stored', 'retention_type' => 'temporary', 'created_by' => $this->user->id, 'location_id' => $this->location->id, 'metadata' => ['purpose' => 'agent']]);
    }
}
