<?php

namespace Tests\Feature;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\AgentEvent;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Models\WhatsAppOutboundMessage;
use App\Models\WhatsAppWebhookEvent;
use App\Services\StockMovementService;
use App\WhatsApp\FakeWhatsAppClient;
use App\WhatsApp\TransientWhatsAppException;
use App\WhatsApp\WhatsAppChannelAdapter;
use App\WhatsApp\WhatsAppClientInterface;
use App\WhatsApp\WhatsAppFailureClassifier;
use Database\Seeders\AuthorizationSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WhatsAppChannelTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsAppClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        config()->set(['whatsapp.enabled' => true, 'whatsapp.verify_token' => 'safe-test-token', 'whatsapp.app_secret' => 'safe-test-secret', 'whatsapp.client' => 'fake', 'whatsapp.max_send_attempts' => 3, 'queue.default' => 'sync']);
        $this->client = new FakeWhatsAppClient;
        $this->app->instance(WhatsAppClientInterface::class, $this->client);
    }

    public function test_webhook_verification_accepts_valid_token_and_rejects_invalid_token(): void
    {
        $this->getJson('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=safe-test-token&hub.challenge=123456')->assertOk()->assertSeeText('123456');
        $this->getJson('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=123456')->assertForbidden();
    }

    public function test_post_webhook_validates_signature_and_queues_quickly(): void
    {
        Queue::fake();
        $raw = json_encode($this->payload('wamid.queue', '5511999999999', 'OI'), JSON_THROW_ON_ERROR);

        $this->postRaw($raw, 'invalid')->assertUnauthorized();
        $this->postRaw($raw)->assertOk()->assertSeeText('EVENT_RECEIVED');
        Queue::assertPushed(ProcessWhatsAppWebhook::class);
        $this->assertDatabaseHas('agent_events', ['event_type' => 'whatsapp_signature_rejected', 'error_code' => 'invalid_signature']);
    }

    public function test_approved_identity_receives_authorized_text_menu(): void
    {
        $this->known('551100000001', ['stock.view']);
        $this->send('wamid.menu', '551100000001', 'OI');

        $this->assertCount(1, $this->client->sent());
        $this->assertStringContainsString('Consultar estoque', $this->client->sent()[0]['text']);
        $this->assertDatabaseHas('agent_events', ['event_type' => 'whatsapp_message_processed', 'channel' => 'whatsapp', 'status' => 'success']);
    }

    public function test_unknown_pending_and_blocked_identities_never_execute_tools(): void
    {
        $this->send('wamid.unknown', '551100000002', 'ESTOQUE');
        $this->assertDatabaseHas('user_external_identities', ['channel' => 'whatsapp', 'external_user_id' => '551100000002', 'status' => 'pending', 'active' => false]);
        $this->assertStringContainsString('não identificado', $this->client->sent()[0]['text']);

        $pending = UserExternalIdentity::query()->where('external_user_id', '551100000002')->firstOrFail();
        $this->send('wamid.pending', '551100000002', 'ESTOQUE');
        $this->assertSame('pending', $pending->fresh()->status);

        $user = User::factory()->unprivileged()->create();
        UserExternalIdentity::query()->create(['user_id' => $user->id, 'channel' => 'whatsapp', 'external_user_id' => '551100000003', 'status' => 'blocked', 'active' => false]);
        $this->send('wamid.blocked', '551100000003', 'ESTOQUE');
        $this->assertDatabaseMissing('agent_events', ['external_message_id' => 'wamid.blocked', 'event_type' => 'tool_executed']);
    }

    public function test_deterministic_stock_query_respects_unit_and_permissions(): void
    {
        $location = Location::query()->create(['name' => 'Catanduva', 'type' => 'store', 'active' => true]);
        $user = $this->known('551100000004', ['stock.view'], [$location]);
        $product = Product::query()->create(['name' => 'Frango com Catupiry', 'stock_unit' => 'un', 'active' => true]);
        app(StockMovementService::class)->record(new RecordStockMovementData($product->id, $location->id, StockMovementType::OpeningBalance, '120', now()->toDateString(), 'wa-stock'));

        $this->send('wamid.stock', '551100000004', 'ESTOQUE CATANDUVA');
        $this->assertStringContainsString('Frango com Catupiry: 120', $this->client->sent()[0]['text']);

        $user->permissions()->detach(Permission::query()->where('name', 'stock.view')->firstOrFail());
        $this->send('wamid.denied', '551100000004', 'ESTOQUE CATANDUVA');
        $this->assertDatabaseHas('agent_events', ['external_message_id' => 'wamid.denied', 'event_type' => 'action_denied']);
    }

    public function test_write_confirmation_sim_no_and_idempotency_use_existing_agent_core(): void
    {
        $location = Location::query()->create(['name' => 'Fábrica Ibirá', 'type' => 'production', 'active' => true]);
        $this->known('551100000005', ['production.create'], [$location]);
        Product::query()->create(['name' => 'Frango com Catupiry', 'stock_unit' => 'un', 'active' => true]);

        $this->send('wamid.production.preview', '551100000005', 'PRODUZIMOS 100 FRANGO COM CATUPIRY');
        $this->assertStringContainsString('Confirmar', $this->client->sent()[0]['text']);
        $this->send('wamid.production.no', '551100000005', 'NÃO');
        $this->assertDatabaseCount('production_records', 0);

        $this->send('wamid.production.preview2', '551100000005', 'PRODUZIMOS 100 FRANGO COM CATUPIRY');
        $this->send('wamid.production.yes', '551100000005', 'SIM');
        $this->assertDatabaseCount('production_records', 1);
        $this->assertDatabaseHas('agent_events', ['event_type' => 'confirmation_executed', 'channel' => 'whatsapp']);
    }

    public function test_duplicate_webhook_message_is_processed_and_sent_only_once(): void
    {
        $this->known('551100000006', ['stock.view']);
        $payload = $this->payload('wamid.duplicate', '551100000006', 'OI');
        app(WhatsAppChannelAdapter::class)->handle($payload);
        app(WhatsAppChannelAdapter::class)->handle($payload);

        $this->assertCount(1, $this->client->sent());
        $this->assertDatabaseCount('whatsapp_webhook_events', 1);
        $this->assertDatabaseHas('agent_events', ['event_type' => 'duplicate_blocked', 'channel' => 'whatsapp']);
    }

    public function test_media_is_normalized_but_returns_controlled_response_without_processing(): void
    {
        $user = $this->known('551100000007', ['stock.view', 'agent.audio.use']);
        UserExternalIdentity::query()->where('user_id', $user->id)->update(['voice_allowed' => true]);
        $payload = $this->payload('wamid.audio', '551100000007', null, 'audio');
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['audio'] = ['id' => 'media-safe-id', 'mime_type' => 'audio/ogg'];
        app(WhatsAppChannelAdapter::class)->handle($payload);

        $this->assertStringContainsString('unidade padrão', $this->client->sent()[0]['text']);
        $this->assertDatabaseHas('whatsapp_inbound_messages', ['external_message_id' => 'wamid.audio', 'status' => 'review_required']);
    }

    public function test_status_and_non_message_events_are_observed_without_becoming_user_messages(): void
    {
        $event = WhatsAppWebhookEvent::query()->create(['external_event_id' => 'source-event', 'event_type' => 'message', 'status' => 'processed']);
        WhatsAppOutboundMessage::query()->create(['whatsapp_webhook_event_id' => $event->id, 'recipient' => '5511', 'body' => 'Olá', 'provider_message_id' => 'wamid.out', 'status' => 'sent']);
        app(WhatsAppChannelAdapter::class)->handle($this->statusPayload('wamid.out', 'delivered'));
        app(WhatsAppChannelAdapter::class)->handle(['object' => 'whatsapp_business_account', 'entry' => []]);

        $this->assertDatabaseHas('whatsapp_outbound_messages', ['provider_message_id' => 'wamid.out', 'status' => 'delivered']);
        $this->assertDatabaseHas('agent_events', ['event_type' => 'whatsapp_status_received', 'status' => 'delivered']);
        $this->assertDatabaseHas('agent_events', ['event_type' => 'whatsapp_event_ignored', 'error_code' => 'unsupported_payload']);
    }

    public function test_temporary_send_errors_use_persistent_job_retry_and_eventually_succeed(): void
    {
        $this->known('551100000008', ['stock.view']);
        $this->client->failNext(2);
        $payload = $this->payload('wamid.retry', '551100000008', 'OI');

        try {
            app(WhatsAppChannelAdapter::class)->handle($payload, 1, false, 5);
            $this->fail('A primeira falha transitória deveria retornar para a fila.');
        } catch (TransientWhatsAppException) {
            $this->assertDatabaseHas('whatsapp_inbound_messages', ['external_message_id' => 'wamid.retry', 'status' => 'retrying', 'attempts' => 1]);
        }
        try {
            app(WhatsAppChannelAdapter::class)->handle($payload, 2, false, 30);
            $this->fail('A segunda falha transitória deveria retornar para a fila.');
        } catch (TransientWhatsAppException) {
            $this->assertDatabaseHas('whatsapp_inbound_messages', ['external_message_id' => 'wamid.retry', 'status' => 'retrying', 'attempts' => 2]);
        }
        app(WhatsAppChannelAdapter::class)->handle($payload, 3, true, 120);

        $this->assertDatabaseHas('whatsapp_outbound_messages', ['recipient' => '551100000008', 'status' => 'sent', 'attempts' => 3]);
        $this->assertDatabaseHas('whatsapp_inbound_messages', ['external_message_id' => 'wamid.retry', 'status' => 'processed', 'attempts' => 3]);
        $this->assertSame(2, AgentEvent::query()->where('event_type', 'whatsapp_send_error')->count());
        $this->assertSame(2, AgentEvent::query()->where('event_type', 'whatsapp_processing_retrying')->count());
        $this->assertDatabaseCount('agent_conversations', 1);
        $this->assertDatabaseMissing('agent_events', ['metadata' => 'temporary_send_failure']);
    }

    public function test_final_transient_failure_is_traceable_and_requires_review(): void
    {
        $this->known('551100000009', ['stock.view']);
        $this->client->failNext(3);
        $payload = $this->payload('wamid.failed', '551100000009', 'OI');

        foreach ([1, 2, 3] as $attempt) {
            try {
                app(WhatsAppChannelAdapter::class)->handle($payload, $attempt, $attempt === 3, [1 => 5, 2 => 30, 3 => 120][$attempt]);
            } catch (TransientWhatsAppException) {
                // O worker registra a exceção e decide entre retry e failed_jobs.
            }
        }

        $this->assertDatabaseHas('whatsapp_inbound_messages', ['external_message_id' => 'wamid.failed', 'status' => 'review_required', 'attempts' => 3]);
        $this->assertDatabaseHas('whatsapp_webhook_events', ['external_event_id' => 'wamid.failed', 'status' => 'failed']);
        $this->assertDatabaseHas('whatsapp_outbound_messages', ['recipient' => '551100000009', 'status' => 'failed', 'attempts' => 3]);
    }

    public function test_permanent_errors_are_not_retryable(): void
    {
        $classifier = app(WhatsAppFailureClassifier::class);

        $this->assertFalse($classifier->isTransient(new DomainException('invalid_operation')));
        $this->assertTrue($classifier->isTransient(new TransientWhatsAppException('timeout')));
        $this->assertSame(3, (new ProcessWhatsAppWebhook([]))->tries());
        $this->assertSame([5, 30, 120], (new ProcessWhatsAppWebhook([]))->backoff());
    }

    public function test_retry_after_confirmed_production_does_not_duplicate_the_operation(): void
    {
        $location = Location::query()->create(['name' => 'Fábrica Retry', 'type' => 'production', 'active' => true]);
        $this->known('551100000010', ['production.create'], [$location]);
        Product::query()->create(['name' => 'Coxinha Retry', 'stock_unit' => 'un', 'active' => true]);
        $this->send('wamid.retry.preview', '551100000010', 'PRODUZIMOS 10 COXINHA RETRY');
        $payload = $this->payload('wamid.retry.confirm', '551100000010', 'SIM');
        $this->client->failNext();

        try {
            app(WhatsAppChannelAdapter::class)->handle($payload, 1, false, 5);
        } catch (TransientWhatsAppException) {
            // Simula a liberação do Job para uma nova tentativa.
        }
        app(WhatsAppChannelAdapter::class)->handle($payload, 2, false, 30);

        $this->assertDatabaseCount('production_records', 1);
        $this->assertDatabaseCount('pending_agent_actions', 1);
        $this->assertDatabaseHas('whatsapp_inbound_messages', ['external_message_id' => 'wamid.retry.confirm', 'status' => 'processed', 'attempts' => 2]);
    }

    public function test_invalid_json_is_rejected_without_dispatching(): void
    {
        $this->postRaw('{invalid-json')->assertUnprocessable();
        $this->assertDatabaseHas('agent_events', ['event_type' => 'whatsapp_event_rejected', 'error_code' => 'invalid_json']);
    }

    private function known(string $externalId, array $permissions, array $locations = []): User
    {
        $user = User::factory()->unprivileged()->create(['name' => 'Operador WhatsApp']);
        $permissions[] = 'agent.text.use';
        foreach ($permissions as $permission) {
            $user->permissions()->attach(Permission::query()->where('name', $permission)->firstOrFail(), ['allowed' => true]);
        }
        $user->locations()->sync(collect($locations)->pluck('id'));
        UserExternalIdentity::query()->create(['user_id' => $user->id, 'channel' => 'whatsapp', 'external_user_id' => $externalId, 'status' => 'approved', 'active' => true]);

        return $user;
    }

    private function send(string $messageId, string $from, ?string $text, string $type = 'text'): void
    {
        app(WhatsAppChannelAdapter::class)->handle($this->payload($messageId, $from, $text, $type));
    }

    private function payload(string $messageId, string $from, ?string $text, string $type = 'text'): array
    {
        $message = ['from' => $from, 'id' => $messageId, 'timestamp' => '1786500000', 'type' => $type];
        if ($type === 'text') {
            $message['text'] = ['body' => $text];
        }

        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'waba-test', 'changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'phone-test'], 'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Contato Teste']]], 'messages' => [$message]]]]]]];
    }

    private function statusPayload(string $messageId, string $status): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'waba-test', 'changes' => [['field' => 'messages', 'value' => ['statuses' => [['id' => $messageId, 'status' => $status, 'timestamp' => '1786500001', 'recipient_id' => '5511']]]]]]]];
    }

    private function postRaw(string $raw, ?string $signature = null): TestResponse
    {
        $signature ??= 'sha256='.hash_hmac('sha256', $raw, 'safe-test-secret');

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature], $raw);
    }
}
