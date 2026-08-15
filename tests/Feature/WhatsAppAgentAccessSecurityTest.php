<?php

namespace Tests\Feature;

use App\Models\AgentConversation;
use App\Models\AgentEvent;
use App\Models\PendingAgentAction;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\AgentAccessPolicy;
use App\Services\ExternalIdentityService;
use App\Services\PhoneNumberNormalizer;
use App\Services\WhatsAppIdentityResolver;
use App\WhatsApp\FakeWhatsAppClient;
use App\WhatsApp\WhatsAppChannelAdapter;
use App\WhatsApp\WhatsAppClientInterface;
use App\WhatsApp\WhatsAppMediaDownloaderInterface;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WhatsAppAgentAccessSecurityTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsAppClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        config()->set(['whatsapp.client' => 'fake', 'whatsapp.enabled' => false, 'ai.provider' => 'fake']);
        $this->client = new FakeWhatsAppClient;
        $this->app->instance(WhatsAppClientInterface::class, $this->client);
    }

    public function test_brazilian_phone_inputs_share_one_canonical_identity(): void
    {
        $normalizer = app(PhoneNumberNormalizer::class);
        $this->assertSame('+5517999999999', $normalizer->normalize('(17) 99999-9999'));
        $this->assertSame('+5517999999999', $normalizer->normalize('17 99999-9999'));
        $this->assertSame('+5517999999999', $normalizer->normalize('+55 17 99999-9999'));
    }

    public function test_only_admin_permission_can_create_and_duplicate_is_blocked(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $ordinary = User::factory()->unprivileged()->create();
        $target = User::factory()->create();
        $payload = ['user_id' => $target->id, 'phone' => '(17) 99999-9999', 'confirm_authorization' => '1'];

        $this->actingAs($ordinary)->post(route('agent.identities.store'), $payload)->assertForbidden();
        $this->actingAs($admin)->post(route('agent.identities.store'), $payload)->assertRedirect();
        $this->actingAs($admin)->from(route('agent.identities.create'))->post(route('agent.identities.store'), $payload)->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('user_external_identities', 1);
    }

    public function test_inactive_identity_and_inactive_user_fail_closed(): void
    {
        $identity = $this->identity('5517999999999');
        $resolver = app(WhatsAppIdentityResolver::class);
        $this->assertTrue($resolver->resolve('5517999999999')->authorized());
        $identity->update(['active' => false]);
        $this->assertSame('inactive_identity', $resolver->resolve('5517999999999')->status);
        $identity->update(['active' => true]);
        $identity->user->update(['active' => false]);
        $this->assertSame('inactive_user', $resolver->resolve('5517999999999')->status);
    }

    public function test_unknown_text_and_media_are_blocked_before_storage_ai_tool_or_download(): void
    {
        $downloader = Mockery::mock(WhatsAppMediaDownloaderInterface::class);
        $downloader->shouldNotReceive('download');
        $this->app->instance(WhatsAppMediaDownloaderInterface::class, $downloader);

        app(WhatsAppChannelAdapter::class)->handle($this->payload('unknown.text', '5517888888888', 'text'));
        app(WhatsAppChannelAdapter::class)->handle($this->payload('unknown.pdf', '5517888888888', 'document'));

        $this->assertDatabaseCount('user_external_identities', 0);
        $this->assertDatabaseCount('whatsapp_inbound_messages', 0);
        $this->assertDatabaseCount('agent_conversations', 0);
        $this->assertDatabaseCount('pending_agent_actions', 0);
        $this->assertDatabaseCount('agent_usage_costs', 0);
        $this->assertSame(2, AgentEvent::query()->where('event_type', 'whatsapp_inbound_blocked')->count());
        $this->assertCount(0, $this->client->sent());
    }

    public function test_channel_permission_is_checked_before_media_download(): void
    {
        $this->identity('5517777777777');
        $downloader = Mockery::mock(WhatsAppMediaDownloaderInterface::class);
        $downloader->shouldNotReceive('download');
        $this->app->instance(WhatsAppMediaDownloaderInterface::class, $downloader);

        app(WhatsAppChannelAdapter::class)->handle($this->payload('denied.pdf', '5517777777777', 'document'));
        $this->assertDatabaseCount('agent_attachments', 0);
        $this->assertDatabaseHas('agent_events', ['external_message_id' => 'denied.pdf', 'error_code' => 'channel_not_allowed']);
    }

    public function test_deactivation_invalidates_pending_actions_immediately(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $identity = $this->identity('5517666666666');
        $conversation = AgentConversation::query()->create(['user_id' => $identity->user_id, 'channel' => 'whatsapp', 'external_conversation_id' => $identity->external_user_id]);
        $pending = PendingAgentAction::query()->create(['user_id' => $identity->user_id, 'agent_conversation_id' => $conversation->id, 'tool_name' => 'stock.adjust', 'payload' => [], 'missing_fields' => [], 'status' => 'pending', 'idempotency_key' => 'security-pending']);

        app(ExternalIdentityService::class)->update($identity, ['status' => 'inactive', 'active' => false, 'menu_enabled' => true, 'structured_commands_allowed' => true, 'free_chat_allowed' => false, 'voice_allowed' => false, 'image_allowed' => false, 'document_allowed' => false, 'reports_allowed' => false], $admin);

        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame('identity_deactivated', $pending->fresh()->failure_reason);
    }

    public function test_permissions_are_not_copied_to_phone_and_revocation_is_immediate(): void
    {
        $identity = $this->identity('5517555555555');
        $permission = Permission::query()->where('name', 'agent.text.use')->firstOrFail();
        $identity->user->permissions()->attach($permission, ['allowed' => true]);
        $this->assertTrue(app(AgentAccessPolicy::class)->canUse($identity->fresh('user'), 'text'));
        $identity->user->permissions()->updateExistingPivot($permission->id, ['allowed' => false]);
        $this->assertFalse(app(AgentAccessPolicy::class)->canUse($identity->fresh('user'), 'text'));
    }

    private function identity(string $externalId): UserExternalIdentity
    {
        $user = User::factory()->unprivileged()->create();

        return UserExternalIdentity::query()->create(['user_id' => $user->id, 'channel' => 'whatsapp', 'external_user_id' => $externalId, 'phone_normalized' => '+'.$externalId, 'status' => 'approved', 'active' => true, 'structured_commands_allowed' => true]);
    }

    private function payload(string $messageId, string $from, string $type): array
    {
        $message = ['from' => $from, 'id' => $messageId, 'timestamp' => '1786500000', 'type' => $type];
        $message[$type] = $type === 'text' ? ['body' => 'MENU'] : ['id' => 'private-media', 'mime_type' => 'application/pdf', 'filename' => 'arquivo.pdf'];

        return ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'waba-test',
            'changes' => [[
                'field' => 'messages',
                'value' => ['metadata' => ['phone_number_id' => 'phone-test'], 'contacts' => [['wa_id' => $from]], 'messages' => [$message]],
            ]],
        ]]];
    }
}
