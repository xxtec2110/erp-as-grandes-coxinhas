<?php

namespace Tests\Feature;

use App\Agent\AgentMessage;
use App\Agent\ErpAgentResponse;
use App\Agent\ErpAgentService;
use App\Models\FinancialAccount;
use App\Models\Location;
use App\Models\Payable;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\RegisterPaymentService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentCorrectionAndReversalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_pending_preview_can_be_corrected_then_cancelled_without_saving(): void
    {
        [$user, $location] = $this->known('correct', ['finance.payables.create']);
        $intent = ['tool' => 'finance.payables.create', 'arguments' => ['description' => 'Fornecedor', 'location_id' => $location->id, 'expected_amount' => '3200', 'competency_date' => now()->toDateString(), 'due_date' => now()->toDateString()]];
        $this->agent('correct', 'registre', 'correct-1', $intent);
        $corrected = $this->agent('correct', 'O valor correto é R$ 3.150', 'correct-2');
        $this->assertSame('3150', $corrected->data['expected_amount']);
        $cancelled = $this->agent('correct', 'NÃO', 'correct-3');
        $this->assertStringContainsString('cancelada', $cancelled->message);
        $this->assertDatabaseCount('payables', 0);
    }

    public function test_user_can_reverse_only_own_recent_payment_after_confirmation(): void
    {
        [$user, $location] = $this->known('undo', ['agent.operations.undo', 'finance.payments.create', 'finance.payments.cancel']);
        $account = FinancialAccount::query()->create(['name' => 'Banco', 'type' => 'bank', 'active' => true]);
        $payable = Payable::query()->create(['description' => 'Dom Armando', 'location_id' => $location->id, 'expected_amount' => '3200', 'competency_date' => now(), 'due_date' => now(), 'status' => 'open', 'created_by' => User::factory()->create()->id, 'idempotency_key' => 'payable-undo']);
        $payment = app(RegisterPaymentService::class)->register($payable, ['amount' => '3200', 'paid_at' => now(), 'financial_account_id' => $account->id, 'payment_method' => 'pix', 'idempotency_key' => 'pay-undo'], $user, 'agent');

        $preview = $this->agent('undo', 'Desfaz o último', 'undo-1');
        $this->assertSame('confirmation', $preview->responseType);
        $this->assertSame('completed', $payment->fresh()->status);
        $this->agent('undo', 'SIM', 'undo-2');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'cancelled', 'cancelled_by' => $user->id]);
        $this->assertDatabaseHas('payables', ['id' => $payable->id, 'status' => 'open']);
        $this->assertDatabaseHas('finance_audits', ['action' => 'payment.cancelled', 'channel' => 'agent']);
    }

    public function test_create_permission_does_not_grant_cancellation_and_other_users_operation_is_not_selected(): void
    {
        [$creator, $location] = $this->known('creator', ['finance.payments.create']);
        [$other] = $this->known('other', ['agent.operations.undo', 'finance.payments.cancel'], [$location]);
        $account = FinancialAccount::query()->create(['name' => 'Banco', 'type' => 'bank', 'active' => true]);
        $payable = Payable::query()->create(['description' => 'CPFL', 'location_id' => $location->id, 'expected_amount' => '100', 'competency_date' => now(), 'due_date' => now(), 'status' => 'open', 'idempotency_key' => 'payable-other']);
        app(RegisterPaymentService::class)->register($payable, ['amount' => '100', 'paid_at' => now(), 'financial_account_id' => $account->id, 'payment_method' => 'pix', 'idempotency_key' => 'other-payment'], $creator, 'agent');

        $response = $this->agent('other', 'Desfaz o último', 'other-1');
        $this->assertFalse($response->success);
        $this->assertStringContainsString('Nenhuma operação', $response->message);
        $this->assertDatabaseHas('payments', ['idempotency_key' => 'other-payment', 'status' => 'completed']);
    }

    private function known(string $external, array $permissions, ?array $locations = null): array
    {
        $user = User::factory()->unprivileged()->create();
        $permissions[] = 'agent.text.use';
        $location = $locations[0] ?? Location::query()->create(['name' => 'Unidade '.$external, 'type' => 'store', 'active' => true]);
        foreach ($permissions as $permission) {
            $user->permissions()->attach(Permission::query()->where('name', $permission)->firstOrFail(), ['allowed' => true]);
        }
        $user->locations()->sync([$location->id]);
        UserExternalIdentity::query()->create(['user_id' => $user->id, 'channel' => 'local-test', 'external_user_id' => $external, 'status' => 'approved', 'active' => true]);

        return [$user, $location];
    }

    private function agent(string $external, string $text, string $id, ?array $intent = null): ErpAgentResponse
    {
        return app(ErpAgentService::class)->handle(new AgentMessage('local-test', $external, $id, $text, metadata: $intent ? ['fake_intent' => $intent] : []));
    }
}
