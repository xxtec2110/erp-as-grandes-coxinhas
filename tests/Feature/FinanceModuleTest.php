<?php

namespace Tests\Feature;

use App\Agent\AgentToolRegistry;
use App\Agent\DeterministicCommandParser;
use App\Agent\PendingAgentActionService;
use App\Models\FinancialAccount;
use App\Models\Location;
use App\Models\Permission;
use App\Models\User;
use App\Services\CancelPaymentService;
use App\Services\CreatePayableService;
use App\Services\FinanceQueryService;
use App\Services\FinanceReportService;
use App\Services\RegisterPaymentService;
use Database\Seeders\AuthorizationSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceModuleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $this->location = Location::query()->create(['name' => 'Loja', 'type' => 'store', 'active' => true]);
    }

    public function test_payable_and_partial_payments_share_official_services_and_are_idempotent(): void
    {
        $payable = app(CreatePayableService::class)->create($this->payableData(), $this->admin);
        $same = app(CreatePayableService::class)->create($this->payableData($payable->idempotency_key), $this->admin);
        $this->assertSame($payable->id, $same->id);
        $account = FinancialAccount::query()->create(['name' => 'Conta PJ', 'type' => 'bank', 'active' => true]);
        $service = app(RegisterPaymentService::class);
        $payment = $service->register($payable, ['amount' => '40', 'paid_at' => '2026-08-12 10:00:00', 'financial_account_id' => $account->id, 'payment_method' => 'pix', 'partner_advance' => false, 'idempotency_key' => (string) Str::uuid()], $this->admin);
        $this->assertSame('partially_paid', $payable->refresh()->status);
        $this->assertSame($payment->id, $service->register($payable, ['amount' => '40', 'paid_at' => '2026-08-12 10:00:00', 'financial_account_id' => $account->id, 'payment_method' => 'pix', 'partner_advance' => false, 'idempotency_key' => $payment->idempotency_key], $this->admin)->id);
        $this->assertDatabaseCount('finance_audits', 2);
    }

    public function test_payment_cannot_exceed_balance(): void
    {
        $p = app(CreatePayableService::class)->create($this->payableData(), $this->admin);
        $a = FinancialAccount::query()->create(['name' => 'Caixa', 'type' => 'cash', 'active' => true]);
        $this->expectException(DomainException::class);
        app(RegisterPaymentService::class)->register($p, ['amount' => '101', 'paid_at' => now(), 'financial_account_id' => $a->id, 'payment_method' => 'cash', 'partner_advance' => false, 'idempotency_key' => (string) Str::uuid()], $this->admin);
    }

    public function test_location_scope_and_permission_block_direct_access(): void
    {
        $u = User::factory()->unprivileged()->create();
        $u->permissions()->attach(Permission::query()->where('name', 'finance.view')->firstOrFail(), ['allowed' => true]);
        $this->actingAs($u)->get(route('finance.index'))->assertOk();
        $this->get(route('finance.create'))->assertForbidden();
    }

    public function test_agent_registry_parser_and_pending_confirmation_use_central_core(): void
    {
        $tool = app(AgentToolRegistry::class)->get('finance.payments.record');
        $this->assertTrue($tool->confirmationRequired);
        $this->assertSame('finance.payments.create', $tool->permission);
        $this->assertSame('finance.reports.summary', app(DeterministicCommandParser::class)->parse('FINANCEIRO MÊS')['tool']);
        $pending = app(PendingAgentActionService::class)->prepare($this->admin, 'finance.payables.create', ['description' => 'Aluguel'], ['location_id'], (string) Str::uuid());
        $this->assertSame(['location_id'], $pending->missing_fields);
        $this->assertSame('pending', $pending->status);
    }

    public function test_report_separates_expected_open_and_cash_paid(): void
    {
        $p = app(CreatePayableService::class)->create($this->payableData(), $this->admin);
        $a = FinancialAccount::query()->create(['name' => 'Sócio', 'type' => 'personal', 'owner_name' => 'Alexandre', 'active' => true]);
        app(RegisterPaymentService::class)->register($p, ['amount' => '25', 'paid_at' => '2026-08-12', 'financial_account_id' => $a->id, 'paid_by_name' => 'Alexandre', 'payment_method' => 'pix', 'partner_advance' => true, 'idempotency_key' => (string) Str::uuid()], $this->admin);
        $s = app(FinanceReportService::class)->summary([$this->location->id], '2026-08-01', '2026-08-31');
        $this->assertSame('100', $s['expected']);
        $this->assertSame('100', $s['open']);
        $this->assertSame('25', $s['paid']);
        $this->assertCount(1, $s['by_payer']);
    }

    public function test_cancelled_payment_remains_audited_but_is_excluded_from_every_realized_total(): void
    {
        $payable = app(CreatePayableService::class)->create($this->payableData(), $this->admin);
        $account = FinancialAccount::query()->create(['name' => 'Conta PJ', 'type' => 'bank', 'active' => true]);
        $payments = app(RegisterPaymentService::class);
        $cancelled = $payments->register($payable, ['amount' => '25', 'paid_at' => '2026-08-12 10:00:00', 'financial_account_id' => $account->id, 'paid_by_name' => 'Sócio A', 'payment_method' => 'pix', 'partner_advance' => false, 'idempotency_key' => (string) Str::uuid()], $this->admin);
        $active = $payments->register($payable, ['amount' => '35', 'paid_at' => '2026-08-12 11:00:00', 'financial_account_id' => $account->id, 'paid_by_name' => 'Sócio B', 'payment_method' => 'pix', 'partner_advance' => false, 'idempotency_key' => (string) Str::uuid()], $this->admin);

        app(CancelPaymentService::class)->cancel($cancelled, $this->admin, 'Lançamento duplicado');

        $summary = app(FinanceReportService::class)->summary([$this->location->id], '2026-08-01', '2026-08-31');
        $outsidePeriod = app(FinanceReportService::class)->summary([$this->location->id], '2026-09-01', '2026-09-30');
        $history = $payable->payments()->get();
        $validPayments = app(FinanceQueryService::class)->payments($this->admin);

        $this->assertSame('35', $summary['paid']);
        $this->assertSame('0', $outsidePeriod['paid']);
        $this->assertCount(1, $summary['by_account']);
        $this->assertCount(1, $summary['by_payer']);
        $this->assertSame('Sócio B', $summary['by_payer']->first()->name);
        $this->assertCount(2, $history);
        $this->assertSame('cancelled', $cancelled->refresh()->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame('Lançamento duplicado', $cancelled->cancellation_reason);
        $this->assertSame('35', $payable->fresh()->paidAmount());
        $this->assertSame('partially_paid', $payable->fresh()->status);
        $this->assertCount(1, $validPayments);
        $this->assertSame($active->id, $validPayments->first()->id);
        $this->assertDatabaseHas('finance_audits', ['action' => 'payment.cancelled', 'auditable_id' => $cancelled->id]);
        $this->actingAs($this->admin)->get(route('finance.index'))->assertOk()->assertSee('35');
    }

    private function payableData(?string $key = null): array
    {
        return ['supplier_id' => null, 'description' => 'Aluguel', 'location_id' => $this->location->id, 'cost_center_id' => null, 'finance_category_id' => null, 'expected_amount' => '100', 'competency_date' => '2026-08-01', 'due_date' => '2026-08-15', 'recurring' => false, 'recurrence_rule' => null, 'notes' => null, 'idempotency_key' => $key ?? (string) Str::uuid()];
    }
}
