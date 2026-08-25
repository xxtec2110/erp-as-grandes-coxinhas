<?php

namespace Tests\Feature;

use App\Agent\AgentMessage;
use App\Agent\AgentToolExecutor;
use App\Agent\AiProviderInterface;
use App\Agent\AudioTranscriptionProviderInterface;
use App\Agent\ErpAgentService;
use App\Agent\PendingAgentActionService;
use App\Models\AgentConversation;
use App\Models\AgentEvent;
use App\Models\Location;
use App\Models\PendingAgentAction;
use App\Models\Permission;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\ExternalIdentityService;
use App\Services\PhoneNumberNormalizer;
use App\Services\WhatsAppIdentityResolver;
use App\WhatsApp\FakeWhatsAppClient;
use App\WhatsApp\WhatsAppChannelAdapter;
use App\WhatsApp\WhatsAppClientInterface;
use App\WhatsApp\WhatsAppMediaDownloaderInterface;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class WhatsAppIdentityAccessGateTest extends TestCase
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
        RateLimiter::clear('whatsapp-agent:1');
    }

    public function test_equivalent_brazilian_formats_resolve_to_one_identity_and_duplicate_active_phone_is_rejected(): void
    {
        $normalizer = app(PhoneNumberNormalizer::class);
        foreach (['+55 17 99999-9999', '55 17 99999-9999', '(17) 99999-9999', '17 99999-9999'] as $input) {
            $this->assertSame('+5517999999999', $normalizer->normalize($input));
        }

        $admin = User::factory()->create(['is_super_admin' => true]);
        $first = User::factory()->create();
        $second = User::factory()->create();
        app(ExternalIdentityService::class)->create(['user_id' => $first->id, 'phone' => '(17) 99999-9999'], $admin);

        $this->expectException(\DomainException::class);
        app(ExternalIdentityService::class)->create(['user_id' => $second->id, 'phone' => '+55 17 99999-9999'], $admin);
    }

    #[Group('whatsapp-identity-e2e')]
    public function test_unknown_text_audio_and_image_are_silently_blocked_before_ai_media_storage_and_outbound(): void
    {
        $ai = Mockery::mock(AiProviderInterface::class);
        $ai->shouldNotReceive('interpret');
        $transcriber = Mockery::mock(AudioTranscriptionProviderInterface::class);
        $transcriber->shouldNotReceive('transcribe');
        $downloader = Mockery::mock(WhatsAppMediaDownloaderInterface::class);
        $downloader->shouldNotReceive('download');
        $this->app->instance(AiProviderInterface::class, $ai);
        $this->app->instance(AudioTranscriptionProviderInterface::class, $transcriber);
        $this->app->instance(WhatsAppMediaDownloaderInterface::class, $downloader);

        foreach (['text', 'audio', 'image'] as $type) {
            app(WhatsAppChannelAdapter::class)->handle($this->messagePayload('unknown-'.$type, '5517888888888', $type, 'MENU'));
        }

        $this->assertDatabaseCount('whatsapp_inbound_messages', 0);
        $this->assertDatabaseCount('agent_conversations', 0);
        $this->assertDatabaseCount('pending_agent_actions', 0);
        $this->assertDatabaseCount('agent_usage_costs', 0);
        $this->assertSame(3, AgentEvent::query()->where('event_type', 'whatsapp_inbound_blocked')->count());
        $this->assertCount(0, $this->client->sent());
    }

    public function test_inactive_identity_inactive_user_and_response_disabled_are_silent_pre_ai_gates(): void
    {
        $ai = Mockery::mock(AiProviderInterface::class);
        $ai->shouldNotReceive('interpret');
        $downloader = Mockery::mock(WhatsAppMediaDownloaderInterface::class);
        $downloader->shouldNotReceive('download');
        $this->app->instance(AiProviderInterface::class, $ai);
        $this->app->instance(WhatsAppMediaDownloaderInterface::class, $downloader);

        $inactiveIdentity = $this->identity('551700000001');
        $inactiveIdentity->update(['active' => false]);
        $inactiveUser = $this->identity('551700000002');
        $inactiveUser->user->update(['active' => false]);
        $muted = $this->identity('551700000003');
        $muted->update(['respond_enabled' => false]);

        foreach ([['inactive-identity', '551700000001'], ['inactive-user', '551700000002'], ['muted', '551700000003']] as [$id, $phone]) {
            app(WhatsAppChannelAdapter::class)->handle($this->messagePayload($id, $phone, 'text', 'OI'));
        }

        $this->assertDatabaseCount('whatsapp_inbound_messages', 0);
        $this->assertCount(0, $this->client->sent());
        $this->assertDatabaseHas('agent_events', ['external_message_id' => 'inactive-identity', 'error_code' => 'inactive_identity']);
        $this->assertDatabaseHas('agent_events', ['external_message_id' => 'inactive-user', 'error_code' => 'inactive_user']);
        $this->assertDatabaseHas('agent_events', ['external_message_id' => 'muted', 'error_code' => 'response_disabled']);
    }

    #[Group('whatsapp-identity-e2e')]
    public function test_greeting_and_help_are_deterministic_use_friendly_name_and_only_show_authorized_capabilities(): void
    {
        $identity = $this->identity('551700000004', ['sales.view']);
        $identity->update(['display_name' => 'Alex']);
        $ai = Mockery::mock(AiProviderInterface::class);
        $ai->shouldNotReceive('interpret');
        $this->app->instance(AiProviderInterface::class, $ai);

        app(WhatsAppChannelAdapter::class)->handle($this->messagePayload('greeting', '551700000004', 'text', 'Oi'));
        app(WhatsAppChannelAdapter::class)->handle($this->messagePayload('help', '551700000004', 'text', 'O que posso fazer?'));
        $salesPermission = Permission::query()->where('name', 'sales.view')->firstOrFail();
        $identity->user->permissions()->updateExistingPivot($salesPermission->id, ['allowed' => false]);
        app(WhatsAppChannelAdapter::class)->handle($this->messagePayload('help-revoked', '551700000004', 'text', 'O que posso consultar?'));

        $this->assertCount(3, $this->client->sent());
        $this->assertStringContainsString('Alex', $this->client->sent()[0]['text']);
        $this->assertStringContainsString('Vendas', $this->client->sent()[1]['text']);
        $this->assertStringNotContainsString('sales.view', $this->client->sent()[1]['text']);
        $this->assertStringNotContainsString('Vendas', $this->client->sent()[2]['text']);
        $this->assertDatabaseMissing('agent_events', ['event_type' => 'ai_called']);
    }

    #[Group('whatsapp-identity-e2e')]
    public function test_known_sales_question_uses_fake_ai_and_executes_authorized_tool(): void
    {
        $location = Location::query()->create(['name' => 'Ibirá', 'type' => 'store', 'active' => true]);
        $allowed = $this->identity('551700000005', ['sales.view', 'agent.free_chat.use']);
        $allowed->update(['free_chat_allowed' => true]);
        $allowed->user->locations()->attach($location);

        app(WhatsAppChannelAdapter::class)->handle($this->messagePayload('sales-allowed', '551700000005', 'text', 'Quanto vendeu ontem?'));

        $this->assertDatabaseHas('agent_events', ['external_message_id' => 'sales-allowed', 'event_type' => 'tool_executed', 'tool_name' => 'sales.summary']);
        $this->assertDatabaseHas('agent_events', ['external_message_id' => 'sales-allowed', 'event_type' => 'ai_called', 'tool_name' => 'sales.summary']);
        $this->assertCount(1, $this->client->sent());
    }

    #[Group('whatsapp-identity-e2e')]
    public function test_known_restricted_user_gets_safe_denial_without_tool_execution(): void
    {
        $location = Location::query()->create(['name' => 'Ibirá', 'type' => 'store', 'active' => true]);
        $restricted = $this->identity('551700000006', ['agent.free_chat.use']);
        $restricted->update(['free_chat_allowed' => true]);
        $restricted->user->locations()->attach($location);

        app(WhatsAppChannelAdapter::class)->handle($this->messagePayload('sales-denied', '551700000006', 'text', 'Quanto vendeu ontem?'));

        $this->assertDatabaseHas('agent_events', ['external_message_id' => 'sales-denied', 'event_type' => 'action_denied']);
        $this->assertDatabaseMissing('agent_events', ['external_message_id' => 'sales-denied', 'event_type' => 'tool_executed']);
        $this->assertStringContainsString('não tem acesso', $this->client->sent()[0]['text']);
        $this->assertStringNotContainsString('ai_tool_not_allowed', $this->client->sent()[0]['text']);
    }

    public function test_phone_replacement_preserves_history_and_old_phone_can_be_safely_reused(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $oldUser = User::factory()->create();
        $newUser = User::factory()->create();
        $service = app(ExternalIdentityService::class);
        $original = $service->create(['user_id' => $oldUser->id, 'phone' => '(17) 90000-0007'], $admin);
        $conversation = AgentConversation::query()->create(['user_id' => $oldUser->id, 'channel' => 'whatsapp', 'external_conversation_id' => $original->external_user_id]);
        $pending = $this->pending($conversation, $oldUser, 'phone-replacement');

        $replacement = $service->replacePhone($original, '(17) 90000-0008', $admin);
        $reused = $service->create(['user_id' => $newUser->id, 'phone' => '+55 17 90000-0007'], $admin);

        $this->assertFalse($original->fresh()->active);
        $this->assertTrue($replacement->active);
        $this->assertTrue($reused->active);
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame($newUser->id, app(WhatsAppIdentityResolver::class)->resolve('5517900000007')->identity?->user_id);
        $this->assertSame($oldUser->id, $conversation->fresh()->user_id);
    }

    public function test_user_reassignment_creates_a_new_audited_binding_and_preserves_old_actor(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $oldUser = User::factory()->create();
        $newUser = User::factory()->create();
        $service = app(ExternalIdentityService::class);
        $original = $service->create(['user_id' => $oldUser->id, 'phone' => '(17) 90000-0009'], $admin);
        $conversation = AgentConversation::query()->create(['user_id' => $oldUser->id, 'channel' => 'whatsapp', 'external_conversation_id' => $original->external_user_id]);
        $pending = $this->pending($conversation, $oldUser, 'user-change');

        $replacement = $service->update($original, $this->policy(['user_id' => $newUser->id, 'display_name' => 'Novo vínculo']), $admin);

        $this->assertNotSame($original->id, $replacement->id);
        $this->assertFalse($original->fresh()->active);
        $this->assertSame($newUser->id, $replacement->user_id);
        $this->assertSame($oldUser->id, $conversation->fresh()->user_id);
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertDatabaseHas('agent_events', ['user_external_identity_id' => $replacement->id, 'event_type' => 'identity_user_changed']);
        $this->assertSame($newUser->id, app(WhatsAppIdentityResolver::class)->resolve('5517900000009')->identity?->user_id);
    }

    public function test_pending_action_cannot_be_confirmed_by_another_user_and_multiple_pending_actions_are_never_chosen_arbitrarily(): void
    {
        $identity = $this->identity('551700000010');
        $other = User::factory()->create();
        $conversation = AgentConversation::query()->create(['user_id' => $identity->user_id, 'channel' => 'whatsapp', 'external_conversation_id' => $identity->external_user_id]);
        $first = $this->pending($conversation, $identity->user, 'pending-first');
        $this->pending($conversation, $identity->user, 'pending-second');

        try {
            app(PendingAgentActionService::class)->confirm($first, $other, app(AgentToolExecutor::class));
            $this->fail('Outro usuário não poderia confirmar a ação.');
        } catch (AuthorizationException) {
            $this->assertSame('pending', $first->fresh()->status);
        }

        $response = app(ErpAgentService::class)->handle(new AgentMessage('whatsapp', $identity->external_user_id, 'ambiguous', 'SIM'));
        $this->assertSame('multiple_pending_actions', $response->errorCode);
        $this->assertSame(2, PendingAgentAction::query()->where('status', 'pending')->count());
    }

    public function test_rate_limit_and_outbound_status_are_stopped_before_intelligent_processing(): void
    {
        $identity = $this->identity('551700000011');
        config()->set('whatsapp.identity_rate_limit_per_minute', 1);
        $ai = Mockery::mock(AiProviderInterface::class);
        $ai->shouldNotReceive('interpret');
        $this->app->instance(AiProviderInterface::class, $ai);

        app(WhatsAppChannelAdapter::class)->handle($this->messagePayload('rate-first', $identity->external_user_id, 'text', 'OI'));
        app(WhatsAppChannelAdapter::class)->handle($this->messagePayload('rate-second', $identity->external_user_id, 'text', 'OI'));
        app(WhatsAppChannelAdapter::class)->handle($this->statusPayload('outbound-only'));

        $this->assertCount(1, $this->client->sent());
        $this->assertDatabaseHas('agent_events', ['external_message_id' => 'rate-second', 'error_code' => 'rate_limited']);
        $this->assertDatabaseCount('agent_conversations', 1);
        $this->assertDatabaseMissing('agent_events', ['external_message_id' => 'outbound-only', 'event_type' => 'message_received']);
    }

    public function test_location_revocation_changes_access_immediately_without_changing_identity(): void
    {
        $ibirA = Location::query()->create(['name' => 'Ibirá', 'type' => 'store', 'active' => true]);
        $catanduva = Location::query()->create(['name' => 'Catanduva', 'type' => 'store', 'active' => true]);
        $identity = $this->identity('551700000014', ['sales.view', 'agent.free_chat.use']);
        $identity->update(['free_chat_allowed' => true]);
        $identity->user->locations()->attach([$ibirA->id, $catanduva->id]);

        $allowed = app(ErpAgentService::class)->handle(new AgentMessage('whatsapp', $identity->external_user_id, 'location-allowed', 'Quanto vendeu ontem em Catanduva?'));
        $this->assertTrue($allowed->success);
        $identity->user->locations()->detach($catanduva);
        $denied = app(ErpAgentService::class)->handle(new AgentMessage('whatsapp', $identity->external_user_id, 'location-denied', 'Quanto vendeu ontem em Catanduva?'));

        $this->assertSame('forbidden', $denied->errorCode);
        $this->assertSame($identity->id, $identity->fresh()->id);
    }

    #[Group('whatsapp-identity-e2e')]
    public function test_whatsapp_write_uses_preview_confirmation_and_idempotent_official_service(): void
    {
        $location = Location::query()->create(['name' => 'Fábrica Teste', 'type' => 'production', 'active' => true]);
        $product = Product::query()->create(['name' => 'Produto Teste Seguro', 'stock_unit' => 'un', 'active' => true]);
        $identity = $this->identity('551700000015', ['stock.opening_balance', 'agent.write.use']);
        $identity->user->locations()->attach($location);

        app(ErpAgentService::class)->handle(new AgentMessage('whatsapp', $identity->external_user_id, 'opening-1', 'Coloque estoque inicial de 12 Produto Teste Seguro na Fábrica Teste.'));
        app(ErpAgentService::class)->handle(new AgentMessage('whatsapp', $identity->external_user_id, 'opening-2', '2026-08-24'));
        $preview = app(ErpAgentService::class)->handle(new AgentMessage('whatsapp', $identity->external_user_id, 'opening-3', 'Contagem física conferida.'));

        $this->assertSame('confirmation', $preview->responseType);
        $this->assertDatabaseCount('stock_movements', 0);
        $confirmed = app(ErpAgentService::class)->handle(new AgentMessage('whatsapp', $identity->external_user_id, 'opening-4', 'SIM'));
        $duplicate = app(ErpAgentService::class)->handle(new AgentMessage('whatsapp', $identity->external_user_id, 'opening-4', 'SIM'));

        $this->assertTrue($confirmed->success);
        $this->assertSame($confirmed->toArray(), $duplicate->toArray());
        $this->assertSame(1, StockMovement::query()->where('product_id', $product->id)->where('location_id', $location->id)->count());
        $this->assertDatabaseHas('pending_agent_actions', ['user_id' => $identity->user_id, 'tool_name' => 'stock.opening_balance.record', 'status' => 'executed']);
    }

    public function test_administration_requires_permission_masks_list_and_supports_search(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $ordinary = User::factory()->unprivileged()->create();
        $viewer = User::factory()->unprivileged()->create();
        $viewer->permissions()->attach(Permission::query()->where('name', 'whatsapp.identities.view')->firstOrFail(), ['allowed' => true]);
        $identity = $this->identity('551700000012', ['sales.view']);
        $identity->update(['display_name' => 'Responsável Ibirá']);

        $this->get(route('agent.identities.index'))->assertRedirect(route('login'));
        $this->actingAs($ordinary)->get(route('agent.identities.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('agent.identities.index', ['q' => 'Responsável']))
            ->assertOk()->assertSee('Responsável Ibirá')->assertDontSee('+551700000012');
        $this->actingAs($admin)->get(route('agent.identities.edit', $identity))
            ->assertOk()->assertSee('+551700000012')->assertSee('O que o agente pode fazer')
            ->assertSee('Vendas')->assertDontSee('sales.view');
        $this->actingAs($viewer)->get(route('agent.identities.index'))->assertOk()->assertDontSee('Autorizar telefone');
        $this->actingAs($viewer)->get(route('agent.identities.edit', $identity))->assertOk()->assertDontSee('Salvar identidade');
    }

    public function test_whatsapp_administration_routes_reject_identities_from_other_channels(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $localIdentity = UserExternalIdentity::query()->create([
            'user_id' => $admin->id,
            'channel' => 'local',
            'external_user_id' => 'local-browser-session',
            'status' => 'approved',
            'active' => true,
            'respond_enabled' => true,
        ]);

        $this->actingAs($admin)->get(route('agent.identities.edit', $localIdentity))->assertNotFound();
        $this->actingAs($admin)->put(route('agent.identities.update', $localIdentity), [])->assertNotFound();
        $this->actingAs($admin)->put(route('agent.identities.phone', $localIdentity), [])->assertNotFound();
        $this->actingAs($admin)->post(route('agent.identities.welcome', $localIdentity))->assertNotFound();
    }

    public function test_web_user_reassignment_requires_explicit_confirmation(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $identity = $this->identity('551700000013');
        $newUser = User::factory()->create();
        $payload = $this->policy(['user_id' => $newUser->id, 'display_name' => 'Novo responsável']);

        $this->actingAs($admin)->from(route('agent.identities.edit', $identity))
            ->put(route('agent.identities.update', $identity), $payload)
            ->assertRedirect(route('agent.identities.edit', $identity))
            ->assertSessionHasErrors('confirm_user_change');
        $this->assertTrue($identity->fresh()->active);

        $response = $this->actingAs($admin)->put(route('agent.identities.update', $identity), [...$payload, 'confirm_user_change' => '1']);
        $replacement = UserExternalIdentity::query()->where('user_id', $newUser->id)->sole();
        $response->assertRedirect(route('agent.identities.edit', $replacement));
        $this->assertFalse($identity->fresh()->active);
    }

    private function identity(string $externalId, array $permissions = []): UserExternalIdentity
    {
        $user = User::factory()->unprivileged()->create();
        foreach (array_unique(['agent.text.use', ...$permissions]) as $permission) {
            $user->permissions()->attach(Permission::query()->where('name', $permission)->firstOrFail(), ['allowed' => true]);
        }

        return UserExternalIdentity::query()->create([
            'user_id' => $user->id, 'channel' => 'whatsapp', 'external_user_id' => $externalId,
            'phone_normalized' => '+'.$externalId, 'display_name' => $user->name,
            'status' => 'approved', 'active' => true, 'respond_enabled' => true, 'structured_commands_allowed' => true,
        ])->load('user');
    }

    private function pending(AgentConversation $conversation, User $user, string $key): PendingAgentAction
    {
        return PendingAgentAction::query()->create([
            'user_id' => $user->id, 'agent_conversation_id' => $conversation->id, 'tool_name' => 'stock.adjust',
            'payload' => [], 'missing_fields' => [], 'status' => 'pending', 'idempotency_key' => $key,
        ]);
    }

    private function policy(array $overrides = []): array
    {
        return [...[
            'status' => 'approved', 'active' => true, 'respond_enabled' => true, 'menu_enabled' => true,
            'structured_commands_allowed' => true, 'free_chat_allowed' => false, 'voice_allowed' => false,
            'image_allowed' => false, 'document_allowed' => false, 'reports_allowed' => false,
        ], ...$overrides];
    }

    private function messagePayload(string $messageId, string $from, string $type, string $text = ''): array
    {
        $message = ['from' => $from, 'id' => $messageId, 'timestamp' => '1787544000', 'type' => $type];
        $message[$type] = match ($type) {
            'text' => ['body' => $text],
            'audio' => ['id' => 'private-audio-'.$messageId, 'mime_type' => 'audio/ogg'],
            'image' => ['id' => 'private-image-'.$messageId, 'mime_type' => 'image/jpeg'],
        };

        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'waba-test', 'changes' => [[
            'field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'phone-test'], 'messages' => [$message]],
        ]]]]];
    }

    private function statusPayload(string $messageId): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'waba-test', 'changes' => [[
            'field' => 'messages', 'value' => ['statuses' => [['id' => $messageId, 'status' => 'delivered', 'timestamp' => '1787544001', 'recipient_id' => '551700000011']]],
        ]]]]];
    }
}
