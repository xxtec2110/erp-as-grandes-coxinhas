<?php

namespace Tests\Feature;

use App\Agent\AgentMessage;
use App\Agent\ErpAgentService;
use App\Models\Location;
use App\Models\ProductionUserPolicy;
use App\Models\User;
use App\Models\UserExternalIdentity;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestrictedProductionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_restricted_profile_blocks_text_without_ai(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $location = Location::query()->create(['name' => 'Fábrica', 'type' => 'production', 'active' => true]);
        ProductionUserPolicy::query()->create(['user_id' => $user->id, 'location_id' => $location->id, 'briefing_time' => '06:00', 'alert_time' => '22:00', 'cutoff_time' => '23:59:59', 'active' => true, 'restricted' => true]);
        UserExternalIdentity::query()->create(['user_id' => $user->id, 'channel' => 'whatsapp', 'external_user_id' => '5511', 'status' => 'approved', 'active' => true, 'structured_commands_allowed' => true, 'free_chat_allowed' => true, 'voice_allowed' => true, 'image_allowed' => true, 'document_allowed' => true, 'reports_allowed' => false]);
        $response = app(ErpAgentService::class)->handle(new AgentMessage('whatsapp', '5511', 'restricted-1', 'Quanto vendeu?'));
        $this->assertFalse($response->success);
        $this->assertSame('restricted_production_profile', $response->errorCode);
        $this->assertDatabaseHas('agent_events', ['event_type' => 'restricted_production_message_blocked']);
        $this->assertDatabaseMissing('agent_events', ['event_type' => 'ai_provider_selected']);
    }
}
