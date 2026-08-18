<?php

namespace Tests\Feature;

use App\Agent\AgentMessage;
use App\Agent\AgentToolExecutor;
use App\Agent\ErpAgentService;
use App\Models\AuthorizationAudit;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\DashboardUserVisibilityService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAgentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_dashboard_agent_tool_blocks_non_master(): void
    {
        $common = User::factory()->unprivileged()->create();
        $target = User::factory()->unprivileged()->create();

        $this->expectException(AuthorizationException::class);
        app(AgentToolExecutor::class)->execute('dashboard.user_widgets.list', ['target_user_id' => $target->id], $common);
    }

    public function test_master_command_generates_preview_cancel_does_not_change_and_confirmation_updates_with_audit(): void
    {
        $master = User::factory()->create(['name' => 'Administrador Master']);
        $target = User::factory()->unprivileged()->create(['name' => 'Guilherme Silva']);
        foreach (['sales.view'] as $permission) {
            $target->permissions()->attach(Permission::query()->where('name', $permission)->firstOrFail(), ['allowed' => true]);
        }
        $this->identity($master, 'master-dashboard');

        $preview = $this->agent('master-dashboard', 'dash-preview-cancel', 'Guilherme pode ver meta diária e sabores mais vendidos.');
        $this->assertTrue($preview->success);
        $this->assertSame('confirmation', $preview->responseType);
        $this->assertStringContainsString('Guilherme Silva', $preview->message);
        $this->assertStringContainsString('Nenhuma outra permissão será alterada', $preview->message);
        $cancelled = $this->agent('master-dashboard', 'dash-cancel', 'NÃO');
        $this->assertStringContainsString('cancelada', $cancelled->message);
        $this->assertDatabaseMissing('user_dashboard_widgets', ['user_id' => $target->id]);

        $this->agent('master-dashboard', 'dash-preview-confirm', 'Guilherme pode ver meta diária e sabores mais vendidos.');
        $confirmed = $this->agent('master-dashboard', 'dash-confirm', 'SIM');
        $this->assertStringContainsString('confirmada', $confirmed->message);
        $this->assertDatabaseHas('user_dashboard_widgets', ['user_id' => $target->id, 'widget_key' => 'dashboard.daily_goal', 'visibility' => 'show']);
        $audit = AuthorizationAudit::query()->where('target_user_id', $target->id)->where('change_type', 'dashboard_visibility_updated')->firstOrFail();
        $this->assertSame('agent', $audit->source);
        $this->assertContains('dashboard.daily_goal', $audit->new_value['added']);
        $this->assertSame('dashboard.user_widgets.update', $audit->context['tool']);
    }

    public function test_master_can_list_hide_only_and_reset_through_deterministic_commands(): void
    {
        $master = User::factory()->create();
        $target = User::factory()->unprivileged()->create(['name' => 'Guilherme Único']);
        foreach (['reports.view', 'stock.view'] as $permission) {
            $target->permissions()->attach(Permission::query()->where('name', $permission)->firstOrFail(), ['allowed' => true]);
        }
        $this->identity($master, 'dashboard-sequence');

        $this->agent('dashboard-sequence', 'only-preview', 'Mostra para o Guilherme apenas produção, estoque e alertas.');
        $this->agent('dashboard-sequence', 'only-confirm', 'SIM');
        $listed = $this->agent('dashboard-sequence', 'list-widgets', 'Quais widgets aparecem para o Guilherme?');
        $this->assertStringContainsString('Resumo operacional', $listed->message);
        $this->assertStringContainsString('Saldo de estoque', $listed->message);

        $this->agent('dashboard-sequence', 'reset-preview', 'Restaura o dashboard padrão do Guilherme.');
        $this->agent('dashboard-sequence', 'reset-confirm', 'CONFIRMAR');
        $this->assertDatabaseMissing('user_dashboard_widgets', ['user_id' => $target->id]);
        $this->assertDatabaseHas('authorization_audits', ['target_user_id' => $target->id, 'change_type' => 'dashboard_visibility_reset', 'source' => 'agent']);
    }

    public function test_ambiguous_user_and_unknown_widget_are_rejected_without_guessing(): void
    {
        $master = User::factory()->create();
        User::factory()->unprivileged()->create(['name' => 'Guilherme Silva']);
        User::factory()->unprivileged()->create(['name' => 'Guilherme Santos']);
        $this->identity($master, 'dashboard-ambiguous');

        $response = $this->agent('dashboard-ambiguous', 'ambiguous', 'Quais widgets aparecem para o Guilherme?');
        $this->assertFalse($response->success);
        $this->assertStringContainsString('mais de um usuário', $response->message);
        $this->assertStringContainsString('Guilherme Silva', $response->message);
        $this->assertStringContainsString('Guilherme Santos', $response->message);

        $this->expectException(\DomainException::class);
        app(DashboardUserVisibilityService::class)->prepareAgentInput('dashboard.user_widgets.update', ['target_user_id' => User::query()->where('name', 'Guilherme Silva')->value('id'), 'show' => ['dashboard.invalid'], 'hide' => []], $master);
    }

    public function test_dashboard_tool_never_grants_missing_functional_permission(): void
    {
        $master = User::factory()->create();
        $target = User::factory()->unprivileged()->create(['name' => 'Maria Operação']);

        try {
            app(DashboardUserVisibilityService::class)->prepareAgentInput('dashboard.user_widgets.update', ['target_user_id' => $target->id, 'show' => ['dashboard.cash_flow'], 'hide' => []], $master);
            $this->fail('A visibilidade não poderia ampliar a permissão funcional.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('não possui permissão funcional', $exception->getMessage());
        }
        $this->assertDatabaseMissing('user_permissions', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('user_dashboard_widgets', ['user_id' => $target->id]);
    }

    private function identity(User $user, string $external): void
    {
        UserExternalIdentity::query()->create(['user_id' => $user->id, 'channel' => 'local-test', 'external_user_id' => $external, 'status' => 'approved', 'active' => true, 'structured_commands_allowed' => true]);
    }

    private function agent(string $external, string $id, string $text)
    {
        return app(ErpAgentService::class)->handle(new AgentMessage('local-test', $external, $id, $text));
    }
}
