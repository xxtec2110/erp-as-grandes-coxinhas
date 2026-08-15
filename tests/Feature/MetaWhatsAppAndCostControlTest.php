<?php

namespace Tests\Feature;

use App\Agent\AgentMessage;
use App\Agent\ErpAgentService;
use App\Mail\AgentCostAlertMail;
use App\Models\AgentUsageCost;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\AgentCostService;
use App\WhatsApp\MetaWhatsAppClient;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MetaWhatsAppAndCostControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_meta_client_sends_grouped_text_and_checks_channel_with_http_fake(): void
    {
        config()->set(['whatsapp.graph_base_url' => 'https://graph.example.test', 'whatsapp.access_token' => 'secret-test', 'whatsapp.phone_number_id' => 'phone-1', 'whatsapp.api_version' => 'v23.0']);
        Http::fake([
            'graph.example.test/v23.0/phone-1/messages' => Http::response(['messages' => [['id' => 'wamid.sent']]]),
            'graph.example.test/v23.0/phone-1*' => Http::response(['id' => 'phone-1', 'quality_rating' => 'GREEN']),
        ]);
        $client = new MetaWhatsAppClient;
        $this->assertSame('wamid.sent', $client->sendText('5511', "Estoque\nFrango: 120"));
        $this->assertSame('operational', $client->channelStatus()['status']);
        Http::assertSentCount(2);
    }

    public function test_audio_requires_both_identity_flag_and_individual_permission(): void
    {
        [$user,$identity] = $this->identity('audio-user');
        $denied = app(ErpAgentService::class)->handle(new AgentMessage('whatsapp', 'audio-user', 'audio-1', messageType: 'audio'));
        $this->assertSame('channel_not_allowed', $denied->errorCode);

        $permission = Permission::query()->where('name', 'agent.audio.use')->firstOrFail();
        $user->permissions()->attach($permission, ['allowed' => true]);
        $identity->update(['voice_allowed' => true]);
        $allowed = app(ErpAgentService::class)->handle(new AgentMessage('whatsapp', 'audio-user', 'audio-2', messageType: 'audio'));
        $this->assertSame('media_processing_unavailable', $allowed->errorCode);
    }

    public function test_administrator_can_enable_and_revoke_channel_audio_without_copying_permissions(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        [$user,$identity] = $this->identity('managed-audio');
        $audio = Permission::query()->where('name', 'agent.audio.use')->firstOrFail();
        $user->permissions()->attach($audio, ['allowed' => true]);
        $base = ['status' => 'approved', 'active' => '1', 'menu_enabled' => '1', 'structured_commands_allowed' => '1', 'voice_allowed' => '1'];
        $this->actingAs($admin)->put(route('agent.identities.update', $identity), $base)->assertRedirect();
        $this->assertTrue($identity->fresh()->voice_allowed);
        $this->assertTrue((bool) $user->fresh()->permissions()->whereKey($audio->id)->first()->pivot->allowed);
        $base['voice_allowed'] = '0';
        $this->actingAs($admin)->put(route('agent.identities.update', $identity), $base)->assertRedirect();
        $this->assertFalse($identity->fresh()->voice_allowed);
        $this->assertTrue((bool) $user->fresh()->permissions()->whereKey($audio->id)->first()->pivot->allowed);
    }

    public function test_cost_thresholds_and_saving_mode_are_configurable_without_stopping_deterministic_erp(): void
    {
        Mail::fake();
        config()->set('whatsapp.alert_email', 'admin@example.test');
        $costs = app(AgentCostService::class);
        $settings = $costs->settings();
        foreach ([['199', 'normal'], ['210', 'warning'], ['260', 'saving'], ['290', 'critical']] as [$amount,$level]) {
            AgentUsageCost::query()->delete();
            $costs->record('ai', 'ai_text', 'cost-'.$amount, metrics: ['estimated_cost' => $amount]);
            $this->assertSame($level, $costs->summary()['level']);
        }
        [$user,$identity] = $this->identity('saving-user');
        $user->permissions()->attach(Permission::query()->where('name', 'stock.view')->firstOrFail(), ['allowed' => true]);
        $identity->update(['free_chat_allowed' => true]);
        $user->permissions()->attach(Permission::query()->where('name', 'agent.free_chat.use')->firstOrFail(), ['allowed' => true]);
        $menu = app(ErpAgentService::class)->handle(new AgentMessage('whatsapp', 'saving-user', 'saving-menu', 'MENU'));
        $free = app(ErpAgentService::class)->handle(new AgentMessage('whatsapp', 'saving-user', 'saving-free', 'converse comigo'));
        $this->assertTrue($menu->success);
        $this->assertSame('command_not_understood', $free->errorCode);
        $this->assertTrue($settings->fresh()->automatic_saving_mode);
        Mail::assertQueued(AgentCostAlertMail::class, 3);
    }

    public function test_usage_is_idempotent_and_reported_per_user_with_token_fields(): void
    {
        $user = User::factory()->create();
        $costs = app(AgentCostService::class);
        $costs->record('ai', 'ai_text', 'usage-1', $user, ['model' => 'economico', 'input_tokens' => 100, 'output_tokens' => 20, 'duration_ms' => 50, 'estimated_cost' => '0.05']);
        $costs->record('ai', 'ai_text', 'usage-1', $user, ['estimated_cost' => '999']);
        $this->assertDatabaseCount('agent_usage_costs', 1);
        $this->assertDatabaseHas('agent_usage_costs', ['user_id' => $user->id, 'input_tokens' => 100, 'output_tokens' => 20, 'model' => 'economico']);
        $this->assertTrue($costs->byUser()->has($user->name));
    }

    private function identity(string $external): array
    {
        $user = User::factory()->unprivileged()->create();
        $user->permissions()->attach(Permission::query()->where('name', 'agent.text.use')->firstOrFail(), ['allowed' => true]);
        $identity = UserExternalIdentity::query()->create(['user_id' => $user->id, 'channel' => 'whatsapp', 'external_user_id' => $external, 'status' => 'approved', 'active' => true, 'structured_commands_allowed' => true]);

        return [$user, $identity];
    }
}
