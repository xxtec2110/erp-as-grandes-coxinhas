<?php

namespace Tests\Feature;

use App\Models\AgentUsageCost;
use App\Models\User;
use App\Services\ExternalIdentityService;
use App\Services\WhatsAppConnectionService;
use App\WhatsApp\FakeWhatsAppClient;
use App\WhatsApp\WhatsAppChannelAdapter;
use App\WhatsApp\WhatsAppClientInterface;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentChannelGoLiveTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsAppClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        config()->set([
            'whatsapp.enabled' => true,
            'whatsapp.provider' => 'meta',
            'whatsapp.client' => 'fake',
            'whatsapp.phone_number_id' => 'business-phone-id-test',
            'whatsapp.verify_token' => 'verify-test-only',
            'whatsapp.app_secret' => 'app-secret-test-only',
            'whatsapp.access_token' => 'access-token-test-only',
            'queue.default' => 'sync',
        ]);
        Http::preventStrayRequests();
        $this->client = new FakeWhatsAppClient;
        $this->app->instance(WhatsAppClientInterface::class, $this->client);
    }

    public function test_master_sender_and_business_destination_remain_distinct(): void
    {
        [$admin, $identity] = $this->masterIdentity();
        $connection = app(WhatsAppConnectionService::class)->configureBusinessPhone('17995550002', $admin);

        $this->assertSame('+5517995550001', $identity->phone_normalized);
        $this->assertSame('+5517995550002', $connection->business_phone_normalized);
        $this->assertNotSame($identity->phone_normalized, $connection->business_phone_normalized);
        $this->assertDatabaseMissing('user_external_identities', ['phone_normalized' => $connection->business_phone_normalized]);
    }

    public function test_known_master_greeting_to_configured_business_number_is_local_and_zero_openai(): void
    {
        [$admin, $identity] = $this->masterIdentity();
        app(WhatsAppConnectionService::class)->configureBusinessPhone('17995550002', $admin);

        app(WhatsAppChannelAdapter::class)->handle($this->payload('wamid.master-greeting', '5517995550001', '5517995550002', 'Bom dia.'));

        $this->assertCount(1, $this->client->sent());
        $this->assertStringContainsString('Alexandre ADM', $this->client->sent()[0]['text']);
        $this->assertSame('5517995550001', $this->client->sent()[0]['recipient']);
        $this->assertDatabaseHas('whatsapp_inbound_messages', ['external_message_id' => 'wamid.master-greeting', 'user_external_identity_id' => $identity->id]);
        $this->assertDatabaseMissing('agent_events', ['external_message_id' => 'wamid.master-greeting', 'event_type' => 'ai_called']);
        $this->assertDatabaseCount('agent_usage_costs', 2);
        $this->assertSame(0, AgentUsageCost::query()->where('provider', 'openai')->count());
        $this->assertSame(2, AgentUsageCost::query()->where('provider', 'meta')->count());
    }

    public function test_unknown_wrong_destination_and_business_self_message_fail_closed(): void
    {
        [$admin] = $this->masterIdentity();
        app(WhatsAppConnectionService::class)->configureBusinessPhone('17995550002', $admin);

        app(WhatsAppChannelAdapter::class)->handle($this->payload('wamid.unknown', '5517995550099', '5517995550002', 'Olá'));
        app(WhatsAppChannelAdapter::class)->handle($this->payload('wamid.wrong-destination', '5517995550001', '5517995550088', 'Olá'));
        app(WhatsAppChannelAdapter::class)->handle($this->payload('wamid.self', '5517995550002', '5517995550002', 'Olá'));

        $this->assertCount(0, $this->client->sent());
        $this->assertDatabaseCount('whatsapp_inbound_messages', 0);
        $this->assertDatabaseCount('agent_conversations', 0);
        $this->assertDatabaseMissing('agent_events', ['event_type' => 'ai_called']);
        $this->assertDatabaseHas('agent_events', ['external_message_id' => 'wamid.unknown', 'error_code' => 'unknown_identity']);
        $this->assertDatabaseHas('agent_events', ['external_message_id' => 'wamid.wrong-destination', 'error_code' => 'wrong_business_destination']);
        $this->assertDatabaseHas('agent_events', ['external_message_id' => 'wamid.self', 'error_code' => 'business_number_self_message']);
    }

    public function test_business_phone_form_normalizes_without_creating_user_identity(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->put(route('agent.whatsapp.business-phone.update'), [
            'business_phone' => '(17) 99555-0002',
            'confirm_business_phone' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('whatsapp_connections', ['business_phone_normalized' => '+5517995550002']);
        $this->assertDatabaseCount('user_external_identities', 0);
        $this->assertDatabaseHas('agent_events', ['event_type' => 'whatsapp_business_phone_configured']);
    }

    public function test_channel_dashboard_reports_health_without_rendering_secrets_or_full_numbers(): void
    {
        [$admin] = $this->masterIdentity();
        app(WhatsAppConnectionService::class)->configureBusinessPhone('17995550002', $admin);

        $response = $this->actingAs($admin)->get(route('agent.whatsapp.index'))->assertOk()
            ->assertSee('Canais do Agente')->assertSee('Agent ERP')->assertSee('OpenAI')->assertSee('WhatsApp')
            ->assertDontSee('access-token-test-only')->assertDontSee('app-secret-test-only')->assertDontSee('verify-test-only')
            ->assertDontSee('+5517995550002');
        $response->assertSee('Conectar WhatsApp');
    }

    private function masterIdentity(): array
    {
        $admin = User::factory()->create(['name' => 'Administrador Master', 'is_super_admin' => true, 'all_locations_access' => true]);
        $identity = app(ExternalIdentityService::class)->create([
            'user_id' => $admin->id,
            'phone' => '17995550001',
            'display_name' => 'Alexandre ADM',
            'respond_enabled' => true,
        ], $admin);

        return [$admin, $identity];
    }

    private function payload(string $messageId, string $from, string $businessPhone, string $text): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'waba-test', 'changes' => [[
            'field' => 'messages',
            'value' => [
                'metadata' => ['phone_number_id' => 'business-phone-id-test', 'display_phone_number' => $businessPhone],
                'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Contato Teste']]],
                'messages' => [['from' => $from, 'id' => $messageId, 'timestamp' => '1787544000', 'type' => 'text', 'text' => ['body' => $text]]],
            ],
        ]]]]];
    }
}
