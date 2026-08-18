<?php

namespace Tests\Feature;

use App\Agent\AgentMessage;
use App\Agent\DeterministicCommandParser;
use App\Agent\ErpAgentService;
use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Enums\StockTransferStatus;
use App\Models\FinancialAccount;
use App\Models\Location;
use App\Models\LossReason;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductLoss;
use App\Models\ProductSale;
use App\Models\PurchaseDocument;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\CreatePayableService;
use App\Services\CreatePurchaseDocumentService;
use App\Services\FinanceQueryService;
use App\Services\FinanceReportService;
use App\Services\OperationalLocationService;
use App\Services\ProductionService;
use App\Services\ProductLossService;
use App\Services\ProductSaleService;
use App\Services\RegisterPaymentService;
use App\Services\StockBalanceService;
use App\Services\StockMovementService;
use App\Services\StockTransferService;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\OperationalLocationsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StrictMultiLocationOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_required_locations_are_idempotent_and_preserve_catanduva(): void
    {
        $catanduva = Location::query()->create(['name' => 'CATANDUVA', 'type' => Location::TYPE_STORE, 'daily_sales_target' => '70', 'active' => true]);

        $this->seed(OperationalLocationsSeeder::class);
        $this->seed(OperationalLocationsSeeder::class);

        $locations = Location::query()->orderBy('id')->get();
        $ibira = $locations->firstWhere('name', 'Unidade Ibirá');
        $factory = $locations->firstWhere('name', 'Fábrica Central');

        $this->assertCount(3, $locations);
        $this->assertSame($catanduva->id, Location::query()->where('name', 'CATANDUVA')->sole()->id);
        $this->assertSame('70.000000', $catanduva->fresh()->daily_sales_target);
        $this->assertSame(Location::TYPE_STORE, $ibira->type);
        $this->assertSame(Location::TYPE_PRODUCTION, $factory->type);
        $this->assertCount(3, collect([$catanduva->id, $ibira->id, $factory->id])->unique());
    }

    public function test_factory_production_transfer_idempotency_and_reversal_keep_balances_isolated(): void
    {
        [$catanduva, $ibira, $factory] = $this->locations();
        $user = User::factory()->create();
        $product = Product::query()->create(['name' => 'Frango com Catupiry', 'stock_unit' => 'un', 'active' => true]);
        $production = app(ProductionService::class)->plan([
            'product_id' => $product->id,
            'location_id' => $factory->id,
            'planned_quantity' => '300',
            'operation_date' => '2026-08-18',
            'idempotency_key' => (string) Str::uuid(),
        ], $user->id);
        app(ProductionService::class)->complete($production, '300', $user->id);

        $service = app(StockTransferService::class);
        $catData = $this->transferData($factory, $catanduva, $product, '150', 'factory-catanduva');
        $ibiraData = $this->transferData($factory, $ibira, $product, '80', 'factory-ibira');
        $catTransfer = $service->complete($catData, $user->id);
        $service->complete($catData, $user->id);
        $service->complete($ibiraData, $user->id);

        $balances = app(StockBalanceService::class);
        $this->assertSame('70.000000', $balances->balance($product, $factory));
        $this->assertSame('150.000000', $balances->balance($product, $catanduva));
        $this->assertSame('80.000000', $balances->balance($product, $ibira));
        $this->assertDatabaseCount('stock_transfers', 2);
        $this->assertDatabaseCount('product_sales', 0);

        $service->reverse($catTransfer, '2026-08-19', 'Transferência lançada para destino incorreto.', $user->id);
        $service->reverse($catTransfer->fresh(), '2026-08-19', 'Transferência lançada para destino incorreto.', $user->id);

        $this->assertSame('220.000000', $balances->balance($product, $factory));
        $this->assertSame('0.000000', $balances->balance($product, $catanduva));
        $this->assertSame('80.000000', $balances->balance($product, $ibira));
        $this->assertSame(StockTransferStatus::Reversed, $catTransfer->fresh()->status);
        $this->assertDatabaseCount('stock_transfers', 2);
        $this->assertSame(2, StockMovement::query()->where('type', StockMovementType::Reversal)->whereNotNull('reversal_of_id')->count());
    }

    public function test_sales_losses_finance_and_purchases_are_strictly_separated(): void
    {
        [$catanduva, $ibira, $factory] = $this->locations();
        $user = User::factory()->create();
        $product = Product::query()->create(['name' => 'Costela com Queijo', 'stock_unit' => 'un', 'active' => true]);
        foreach ([$catanduva, $ibira, $factory] as $location) {
            $this->openingStock($product, $location, '100');
        }
        app(ProductSaleService::class)->record(['product_id' => $product->id, 'location_id' => $catanduva->id, 'quantity' => '10', 'unit_price' => '12', 'operation_date' => '2026-08-18', 'idempotency_key' => (string) Str::uuid()], $user);
        $reason = LossReason::query()->create(['name' => 'Avaria', 'active' => true]);
        app(ProductLossService::class)->record(['product_id' => $product->id, 'location_id' => $ibira->id, 'loss_reason_id' => $reason->id, 'quantity' => '5', 'operation_date' => '2026-08-18', 'idempotency_key' => (string) Str::uuid()], $user->id);

        $balances = app(StockBalanceService::class);
        $this->assertSame('90.000000', $balances->balance($product, $catanduva));
        $this->assertSame('95.000000', $balances->balance($product, $ibira));
        $this->assertSame('100.000000', $balances->balance($product, $factory));
        $this->assertSame($catanduva->id, ProductSale::query()->sole()->location_id);
        $this->assertSame($ibira->id, ProductLoss::query()->sole()->location_id);

        $catPayable = app(CreatePayableService::class)->create($this->payableData($catanduva, 'Energia Catanduva', '100'), $user);
        app(CreatePayableService::class)->create($this->payableData($ibira, 'Energia Ibirá', '200'), $user);
        app(CreatePayableService::class)->create($this->payableData($factory, 'Manutenção da fábrica', '300'), $user);
        $reports = app(FinanceReportService::class);
        $this->assertSame('100', $reports->summary([$catanduva->id], '2026-08-01', '2026-08-31')['expected']);
        $this->assertSame('200', $reports->summary([$ibira->id], '2026-08-01', '2026-08-31')['expected']);
        $this->assertSame('300', $reports->summary([$factory->id], '2026-08-01', '2026-08-31')['expected']);

        $wrongAccount = FinancialAccount::query()->create(['name' => 'Caixa Ibirá', 'type' => 'cash', 'location_id' => $ibira->id, 'active' => true]);
        try {
            app(RegisterPaymentService::class)->register($catPayable, $this->paymentData($wrongAccount), $user);
            $this->fail('Uma conta financeira de outra unidade não pode pagar este título.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('mesma unidade', $exception->getMessage());
        }
        $catAccount = FinancialAccount::query()->create(['name' => 'Caixa Catanduva', 'type' => 'cash', 'location_id' => $catanduva->id, 'active' => true]);
        app(RegisterPaymentService::class)->register($catPayable, $this->paymentData($catAccount), $user);
        $this->assertCount(1, app(FinanceQueryService::class)->payments($user, ['location_id' => $catanduva->id]));
        $this->assertCount(0, app(FinanceQueryService::class)->payments($user, ['location_id' => $ibira->id]));

        foreach ([[$catanduva, '100'], [$ibira, '200'], [$factory, '300']] as [$location, $amount]) {
            app(CreatePurchaseDocumentService::class)->create(['document_type' => 'invoice', 'issue_date' => '2026-08-18', 'total_amount' => $amount, 'location_id' => $location->id, 'idempotency_key' => (string) Str::uuid(), 'items' => []], $user);
        }
        $this->assertSame(1, PurchaseDocument::query()->where('location_id', $catanduva->id)->count());
        $this->assertSame(1, PurchaseDocument::query()->where('location_id', $ibira->id)->count());
        $this->assertSame(1, PurchaseDocument::query()->where('location_id', $factory->id)->count());
    }

    public function test_location_and_widget_authorizations_remain_independent_and_backend_enforced(): void
    {
        [$catanduva, $ibira, $factory] = $this->locations();
        $master = User::factory()->create(['is_super_admin' => true]);
        $administrator = User::factory()->unprivileged()->create();
        $administrator->roles()->attach(Role::query()->where('name', 'administrator')->firstOrFail());
        $target = User::factory()->unprivileged()->create();
        $target->locations()->sync([$catanduva->id]);

        $this->actingAs($administrator)->put(route('users.access.update', $target), [
            'role_ids' => [], 'location_ids' => [$ibira->id], 'all_locations_access' => false,
        ])->assertForbidden();
        $this->assertTrue($target->fresh()->locations->contains($catanduva));
        $this->assertFalse($target->fresh()->locations->contains($ibira));

        $this->actingAs($master)->put(route('users.access.update', $target), [
            'location_ids' => [$ibira->id], 'all_locations_access' => false, 'default_location_id' => $ibira->id,
        ])->assertRedirect();
        $this->assertTrue($target->fresh()->locations->contains($ibira));
        $this->assertSame($ibira->id, $target->fresh()->default_location_id);

        $target->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'sales.view')->firstOrFail()->id => ['allowed' => true],
        ]);
        $this->actingAs($target)->get(route('dashboard', ['location_id' => $catanduva->id]))->assertForbidden();
        $this->get(route('dashboard', ['location_id' => $ibira->id]))->assertOk()->assertDontSee('CATANDUVA');
        $this->actingAs($master)->get(route('dashboard', ['location_id' => $factory->id]))->assertForbidden();
    }

    public function test_agent_uses_deterministic_location_access_and_atomic_transfer_flows(): void
    {
        [$catanduva, $ibira, $factory] = $this->locations();
        $master = User::factory()->create(['name' => 'Admin Master', 'is_super_admin' => true, 'default_location_id' => $catanduva->id]);
        $target = User::factory()->unprivileged()->create(['name' => 'Guilherme Silva']);
        $target->locations()->sync([$catanduva->id]);
        UserExternalIdentity::query()->create(['user_id' => $master->id, 'channel' => 'local-test', 'external_user_id' => 'master', 'status' => 'approved', 'active' => true, 'structured_commands_allowed' => true, 'free_chat_allowed' => false]);
        $product = Product::query()->create(['name' => 'Frango com Catupiry', 'stock_unit' => 'un', 'active' => true]);
        $this->openingStock($product, $factory, '300');

        $parser = app(DeterministicCommandParser::class);
        $this->assertSame('use_location', $parser->parse('Use Ibirá.')['action']);
        $this->assertSame('agent.access.location.grant', $parser->parse('Libere o Guilherme para Ibirá.')['tool']);
        $this->assertSame('agent.access.location.revoke', $parser->parse('Retire Ibirá do Guilherme.')['tool']);
        $this->assertSame('agent.access.locations.replace', $parser->parse('Deixe o Guilherme somente em Catanduva.')['tool']);
        $this->assertSame('agent.access.locations.list', $parser->parse('Quais unidades o Guilherme pode acessar?')['tool']);
        $this->assertSame('transfers.complete', $parser->parse('Envie 80 Frango com Catupiry da fábrica para Ibirá.')['tool']);

        $agent = app(ErpAgentService::class);
        $this->assertTrue($agent->handle($this->message('use-ibira', 'Use Ibirá.'))->success);
        $preview = $agent->handle($this->message('grant-ibira', 'Libere o Guilherme para Ibirá.'));
        $this->assertSame('confirmation', $preview->responseType);
        $this->assertStringContainsString('Unidade Ibirá', $preview->message);
        $agent->handle($this->message('grant-confirm', 'SIM'));
        $this->assertTrue($target->fresh()->locations->contains($ibira));

        $list = $agent->handle($this->message('list-locations', 'Quais unidades o Guilherme pode acessar?'));
        $this->assertStringContainsString('Unidade Ibirá', $list->message);
        $only = $agent->handle($this->message('only-catanduva', 'Deixe o Guilherme somente em Catanduva.'));
        $this->assertStringContainsString('MANTER', $only->message);
        $agent->handle($this->message('only-confirm', 'CONFIRMAR'));
        $this->assertEquals([$catanduva->id], $target->fresh()->locations()->pluck('locations.id')->all());

        $transfer = $agent->handle($this->message('transfer-ibira', 'Envie 80 Frango com Catupiry da fábrica para Ibirá.'));
        $this->assertStringContainsString('Fábrica Central: -80', $transfer->message);
        $agent->handle($this->message('transfer-confirm', 'SIM'));
        $balances = app(StockBalanceService::class);
        $this->assertSame('220.000000', $balances->balance($product, $factory));
        $this->assertSame('80.000000', $balances->balance($product, $ibira));
        $this->assertSame('0.000000', $balances->balance($product, $catanduva));
        $this->assertDatabaseHas('authorization_audits', ['target_user_id' => $target->id, 'source' => 'agent']);
        $this->assertDatabaseHas('stock_transfers', ['source_location_id' => $factory->id, 'destination_location_id' => $ibira->id, 'status' => StockTransferStatus::Received->value]);
    }

    /** @return array{Location, Location, Location} */
    private function locations(): array
    {
        $catanduva = Location::query()->create(['name' => 'CATANDUVA', 'type' => Location::TYPE_STORE, 'active' => true]);
        ['ibira' => $ibira, 'factory' => $factory] = app(OperationalLocationService::class)->ensureRequiredLocations();

        return [$catanduva, $ibira, $factory];
    }

    /** @return array<string, mixed> */
    private function transferData(Location $source, Location $destination, Product $product, string $quantity, string $key): array
    {
        return ['source_location_id' => $source->id, 'destination_location_id' => $destination->id, 'product_id' => $product->id, 'quantity' => $quantity, 'operation_date' => '2026-08-18', 'idempotency_key' => $key];
    }

    private function openingStock(Product $product, Location $location, string $quantity): void
    {
        app(StockMovementService::class)->record(new RecordStockMovementData($product->id, $location->id, StockMovementType::OpeningBalance, $quantity, '2026-08-18', (string) Str::uuid()));
    }

    /** @return array<string, mixed> */
    private function payableData(Location $location, string $description, string $amount): array
    {
        return ['description' => $description, 'location_id' => $location->id, 'expected_amount' => $amount, 'competency_date' => '2026-08-18', 'due_date' => '2026-08-25', 'recurring' => false, 'idempotency_key' => (string) Str::uuid()];
    }

    /** @return array<string, mixed> */
    private function paymentData(FinancialAccount $account): array
    {
        return ['amount' => '50', 'paid_at' => '2026-08-18', 'financial_account_id' => $account->id, 'payment_method' => 'pix', 'partner_advance' => false, 'idempotency_key' => (string) Str::uuid()];
    }

    private function message(string $id, string $text): AgentMessage
    {
        return new AgentMessage('local-test', 'master', $id, $text);
    }
}
