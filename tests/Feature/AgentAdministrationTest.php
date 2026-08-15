<?php

namespace Tests\Feature;

use App\Models\AgentConversation;
use App\Models\AgentEvent;
use App\Models\AuthorizationAudit;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AgentAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_administrator_can_authorize_phone_without_changing_existing_access_rules(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $operator = User::factory()->create();
        $location = Location::query()->create(['name' => 'Loja Ibirá', 'type' => 'store', 'active' => true]);
        $role = Role::query()->where('name', 'operator')->firstOrFail();
        $permission = Permission::query()->where('name', 'stock.view')->firstOrFail();
        $operator->roles()->sync([$role->id]);
        $operator->locations()->sync([$location->id]);
        $operator->permissions()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);

        $response = $this->actingAs($admin)->post(route('agent.identities.store'), [
            'user_id' => $operator->id,
            'phone' => '(17) 99999-9999',
            'confirm_authorization' => '1',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('user_external_identities', ['user_id' => $operator->id, 'phone_normalized' => '+5517999999999', 'status' => 'approved', 'active' => true]);
        $this->assertTrue($operator->fresh()->roles->contains($role));
        $this->assertTrue($operator->fresh()->locations->contains($location));
        $this->assertDatabaseHas('agent_events', ['event_type' => 'identity_activated']);
    }

    public function test_authorization_requires_existing_user(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin)->from(route('agent.identities.create'))->post(route('agent.identities.store'), [
            'user_id' => 999999, 'phone' => '(17) 99999-9999',
        ])->assertRedirect(route('agent.identities.create'))->assertSessionHasErrors('user_id');

        $this->assertDatabaseCount('user_external_identities', 0);
    }

    public function test_agent_administration_is_protected_and_available_to_authorized_user(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $ordinary = User::factory()->unprivileged()->create();

        $this->get(route('agent.identities.index'))->assertRedirect(route('login'));
        $this->actingAs($ordinary)->get(route('agent.identities.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('agent.identities.index'))->assertOk();
        $this->actingAs($admin)->get(route('agent.observability'))->assertOk();
    }

    public function test_simulator_defaults_to_fake_and_blocks_live_provider_without_all_guards(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->get(route('agent.simulator'))->assertOk()->assertSee('🧪 FAKE')->assertSee('🔴 LIVE TEST');
        $this->actingAs($admin)->post(route('agent.simulator.send'), ['provider' => 'fake', 'text' => 'OI'])->assertOk()->assertSee('Agente');
        $this->actingAs($admin)->from(route('agent.simulator'))->post(route('agent.simulator.send'), ['provider' => 'live', 'text' => 'teste'])->assertRedirect(route('agent.simulator'))->assertSessionHasErrors('provider');
    }

    public function test_simulator_transcribes_fake_audio_once_and_uses_deterministic_parser(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $location = Location::query()->create(['name' => 'Unidade de áudio', 'type' => 'production', 'active' => true]);

        $response = $this->actingAs($admin)->post(route('agent.simulator.send'), [
            'provider' => 'fake',
            'attachment' => UploadedFile::fake()->createWithContent('audio.ogg', 'OggSfake-audio'),
            'fake_transcription' => 'MENU',
            'location_id' => $location->id,
        ]);

        $response->assertOk()->assertSee('O que você deseja fazer?');
        $this->assertDatabaseHas('agent_usage_costs', ['provider' => 'fake', 'usage_type' => 'ai_audio']);
        $this->assertDatabaseHas('agent_events', ['event_type' => 'audio_transcribed']);
        $this->assertDatabaseMissing('agent_events', ['event_type' => 'ai_called']);
    }

    public function test_observability_lists_events_and_interaction_without_exposing_a_parallel_audit(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $conversation = AgentConversation::query()->create(['user_id' => $admin->id, 'channel' => 'simulator', 'status' => 'active']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'MENU']);
        AgentEvent::query()->create(['user_id' => $admin->id, 'agent_conversation_id' => $conversation->id, 'event_type' => 'message_received', 'channel' => 'simulator', 'status' => 'processed']);

        $this->actingAs($admin)->get(route('agent.observability'))->assertOk()->assertSee('message_received');
        $this->actingAs($admin)->get(route('agent.interactions.show', $conversation))->assertOk()->assertSee('MENU');
        $this->assertSame(0, AuthorizationAudit::query()->count());
    }
}
