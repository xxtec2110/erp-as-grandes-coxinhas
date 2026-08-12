<?php

namespace Tests\Feature;

use App\Agent\AgentMessage;
use App\Agent\AiProviderInterface;
use App\Agent\ErpAgentService;
use App\Agent\FakeAiProvider;
use App\Agent\UnavailableAiProvider;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserExternalIdentity;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentCapabilityAndProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_text_requires_explicit_permission_and_does_not_grant_tool_access(): void
    {
        [$denied] = $this->identity('text-denied');
        $response = $this->agent('text-denied', 'MENU', 'text-1');
        $this->assertSame('channel_not_allowed', $response->errorCode);

        $this->grant($denied, 'agent.text.use');
        $menu = $this->agent('text-denied', 'MENU', 'text-2');
        $this->assertTrue($menu->success);
        $this->assertEmpty($menu->options);
        $tool = $this->agent('text-denied', 'CONTAS A PAGAR', 'text-3');
        $this->assertSame('forbidden', $tool->errorCode);
    }

    public function test_image_document_and_audio_each_require_flag_and_permission(): void
    {
        foreach ([['image', 'agent.image.use', 'image_allowed'], ['document', 'agent.document.use', 'document_allowed'], ['audio', 'agent.audio.use', 'voice_allowed']] as [$type, $permission, $flag]) {
            [$user, $identity] = $this->identity($type);
            $identity->update([$flag => true]);
            $this->assertSame('channel_not_allowed', $this->agent($type, null, $type.'-denied', $type)->errorCode);
            $this->grant($user, $permission);
            $this->assertSame('media_processing_unavailable', $this->agent($type, null, $type.'-allowed', $type)->errorCode);
        }
    }

    public function test_free_chat_is_separate_and_provider_selection_is_observed(): void
    {
        [$user, $identity] = $this->identity('chat');
        $this->grant($user, 'agent.text.use');
        $identity->update(['free_chat_allowed' => true]);
        $this->assertSame('command_not_understood', $this->agent('chat', 'converse comigo', 'chat-denied')->errorCode);
        $this->grant($user, 'agent.free_chat.use');
        $this->agent('chat', 'converse comigo', 'chat-allowed');
        $this->assertDatabaseHas('agent_events', ['external_message_id' => 'chat-allowed', 'event_type' => 'ai_provider_selected']);
    }

    public function test_testing_resolves_fake_but_production_never_uses_it_and_fails_closed(): void
    {
        config()->set('agent_costs.provider', 'fake');
        $this->app->forgetInstance(AiProviderInterface::class);
        $this->assertInstanceOf(FakeAiProvider::class, app(AiProviderInterface::class));

        $this->app->detectEnvironment(fn () => 'production');
        $this->app->forgetInstance(AiProviderInterface::class);
        $this->assertInstanceOf(UnavailableAiProvider::class, app(AiProviderInterface::class));

        [$user, $identity] = $this->identity('production-ai');
        $this->grant($user, 'agent.text.use');
        $this->grant($user, 'agent.free_chat.use');
        $identity->update(['free_chat_allowed' => true]);
        $response = $this->agent('production-ai', 'pergunta livre', 'production-ai-1');
        $this->assertSame('ai_provider_unavailable', $response->errorCode);
        $this->assertDatabaseHas('agent_events', ['event_type' => 'ai_provider_unavailable', 'error_code' => 'ai_provider_unavailable']);

        $deterministic = $this->agent('production-ai', 'MENU', 'production-ai-2');
        $this->assertTrue($deterministic->success);
    }

    private function identity(string $external): array
    {
        $user = User::factory()->unprivileged()->create();
        $identity = UserExternalIdentity::query()->create(['user_id' => $user->id, 'channel' => 'local-test', 'external_user_id' => $external, 'status' => 'approved', 'active' => true, 'structured_commands_allowed' => true]);

        return [$user, $identity];
    }

    private function grant(User $user, string $permission): void
    {
        $user->permissions()->syncWithoutDetaching([Permission::query()->where('name', $permission)->firstOrFail()->id => ['allowed' => true]]);
    }

    private function agent(string $external, ?string $text, string $id, string $type = 'text')
    {
        return app(ErpAgentService::class)->handle(new AgentMessage('local-test', $external, $id, $text, messageType: $type));
    }
}
