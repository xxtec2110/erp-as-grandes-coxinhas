<?php

namespace Tests\Feature;

use App\Agent\AiInterpretation;
use App\Agent\AiProviderInterface;
use App\Models\FinancialAccount;
use App\Models\Location;
use App\Models\PendingAgentAction;
use App\Models\Permission;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\CreatePayableService;
use App\WhatsApp\DownloadedMedia;
use App\WhatsApp\FakeWhatsAppClient;
use App\WhatsApp\WhatsAppChannelAdapter;
use App\WhatsApp\WhatsAppClientInterface;
use App\WhatsApp\WhatsAppMediaDownloaderInterface;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class WhatsAppDocumentPipelineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Location $location;

    private Supplier $supplier;

    private FakeWhatsAppClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        Storage::fake('local');
        config()->set(['whatsapp.client' => 'fake', 'whatsapp.media_downloader' => 'fake', 'ai.provider' => 'fake']);
        $this->client = new FakeWhatsAppClient;
        $this->app->instance(WhatsAppClientInterface::class, $this->client);
        $this->location = Location::query()->create(['name' => 'Catanduva', 'type' => 'store', 'active' => true]);
        $this->supplier = Supplier::query()->create(['name' => 'Fornecedor Fiscal', 'document_type' => 'cnpj', 'document_number' => '11222333000181', 'active' => true]);
        $this->user = User::factory()->unprivileged()->create();
        foreach (['agent.document.use', 'agent.text.use', 'agent.write.use', 'finance.payables.create', 'finance.payments.create', 'purchases.create'] as $permission) {
            $this->user->permissions()->attach(Permission::query()->where('name', $permission)->firstOrFail(), ['allowed' => true]);
        }
        $this->user->locations()->attach($this->location);
        UserExternalIdentity::query()->create(['user_id' => $this->user->id, 'channel' => 'whatsapp', 'external_user_id' => '551100002001', 'status' => 'approved', 'active' => true, 'structured_commands_allowed' => true, 'document_allowed' => true]);
    }

    public function test_fake_pdf_receipt_requires_preview_then_records_payment_once(): void
    {
        $account = FinancialAccount::query()->create(['name' => 'Conta PJ', 'type' => 'bank', 'active' => true, 'location_id' => $this->location->id]);
        $payable = app(CreatePayableService::class)->create(['supplier_id' => $this->supplier->id, 'description' => 'Compra teste', 'location_id' => $this->location->id, 'expected_amount' => '1250', 'competency_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'recurring' => false, 'idempotency_key' => 'payable-proof-test'], $this->user);
        $this->bindMedia('proof-media', '%PDF-proof');
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('interpret')->once()->andReturn(AiInterpretation::fromArray(['intent' => 'record_payment', 'tool' => 'finance.payments.record', 'confidence' => 0.98, 'fields' => ['payable_id' => $payable->id, 'amount' => '1250', 'paid_at' => now()->toDateTimeString(), 'financial_account_id' => $account->id, 'payment_method' => 'pix', 'supplier_name' => 'Fornecedor Fiscal', 'supplier_document_number' => '11.222.333/0001-81'], 'missing_fields' => [], 'source_type' => 'document', 'document_type' => 'payment_receipt', 'summary' => 'Comprovante de teste.']));
        $this->app->instance(AiProviderInterface::class, $provider);

        app(WhatsAppChannelAdapter::class)->handle($this->documentPayload('wamid.proof', 'proof-media'));
        $this->assertDatabaseCount('payments', 0);
        $this->assertStringContainsString('Confirmar', $this->client->sent()[0]['text']);
        app(WhatsAppChannelAdapter::class)->handle($this->textPayload('wamid.proof.confirm', 'SIM'));

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['payable_id' => $payable->id, 'amount' => '1250.00']);
        $this->assertDatabaseHas('pending_agent_actions', ['status' => 'executed']);
    }

    public function test_fake_supplier_invoice_matches_cnpj_and_never_updates_prices_automatically(): void
    {
        $this->bindMedia('invoice-media', '%PDF-invoice');
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('interpret')->once()->andReturn(AiInterpretation::fromArray(['intent' => 'create_purchase_document', 'tool' => 'purchases.documents.create', 'confidence' => 0.97, 'fields' => ['supplier_name' => 'Nome OCR diferente', 'supplier_document_number' => '11.222.333/0001-81', 'document_type' => 'invoice', 'document_number' => 'NF-100', 'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(5)->toDateString(), 'total_amount' => '220', 'items' => [['description' => 'Muçarela', 'quantity' => '5', 'unit_price' => '44']]], 'missing_fields' => [], 'source_type' => 'document', 'document_type' => 'purchase_document', 'summary' => 'Nota de teste.']));
        $this->app->instance(AiProviderInterface::class, $provider);

        app(WhatsAppChannelAdapter::class)->handle($this->documentPayload('wamid.invoice', 'invoice-media'));

        $action = PendingAgentAction::query()->firstOrFail();
        $this->assertSame($this->supplier->id, $action->payload['supplier_id']);
        $this->assertSame($this->location->id, $action->payload['location_id']);
        $this->assertDatabaseCount('purchase_documents', 0);
        $this->assertDatabaseCount('ingredient_prices', 0);
    }

    private function bindMedia(string $id, string $contents): void
    {
        $downloader = Mockery::mock(WhatsAppMediaDownloaderInterface::class);
        $downloader->shouldReceive('download')->once()->with($id)->andReturn(new DownloadedMedia($id, 'application/pdf', $id.'.pdf', $contents));
        $this->app->instance(WhatsAppMediaDownloaderInterface::class, $downloader);
    }

    private function documentPayload(string $messageId, string $mediaId): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'phone-test'], 'messages' => [['id' => $messageId, 'from' => '551100002001', 'timestamp' => '1786636800', 'type' => 'document', 'document' => ['id' => $mediaId, 'mime_type' => 'application/pdf', 'filename' => 'teste.pdf']]]]]]]]];
    }

    private function textPayload(string $messageId, string $text): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'phone-test'], 'messages' => [['id' => $messageId, 'from' => '551100002001', 'timestamp' => '1786636800', 'type' => 'text', 'text' => ['body' => $text]]]]]]]]];
    }
}
