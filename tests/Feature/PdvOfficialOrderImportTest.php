<?php

namespace Tests\Feature;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Acquirer;
use App\Models\CardBrand;
use App\Models\Location;
use App\Models\PaymentFee;
use App\Models\PdvConnection;
use App\Models\PdvIntegrationEvent;
use App\Models\PdvOrder;
use App\Models\PdvOrderItem;
use App\Models\PdvOrderPayment;
use App\Models\PdvPaymentMethodMapping;
use App\Models\PdvProductMapping;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\ProductSaleOrder;
use App\Models\ProductSalePayment;
use App\Models\ProductSalePaymentAllocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Pdv\IntegrationNotConfiguredException;
use App\Pdv\PdvOrderImportBlockedException;
use App\Services\MoneyAllocationService;
use App\Services\PaymentFeeReportService;
use App\Services\PdvOrderBatchImportService;
use App\Services\PdvOrderImportPlanService;
use App\Services\PdvOrderImportService;
use App\Services\PdvOrderReversalService;
use App\Services\ProductSaleService;
use App\Services\SalesSummaryService;
use App\Services\StockBalanceService;
use App\Services\StockMovementService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Database\Seeders\AuthorizationSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PdvOfficialOrderImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Location $location;

    private Location $otherLocation;

    private PdvConnection $connection;

    /** @var array<string,Product> */
    private array $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create(['is_super_admin' => true, 'all_locations_access' => true]);
        $this->location = Location::query()->create(['name' => 'Unidade teste', 'type' => Location::TYPE_STORE, 'active' => true]);
        $this->otherLocation = Location::query()->create(['name' => 'Outra unidade', 'type' => Location::TYPE_STORE, 'active' => true]);
        $this->connection = PdvConnection::query()->firstOrFail();
        $this->connection->update(['location_id' => $this->location->id, 'name' => 'GrandChef teste', 'enabled' => true]);
        $this->products = [
            'P1' => Product::query()->create(['name' => 'Produto 1', 'stock_unit' => 'un', 'active' => true]),
            'P2' => Product::query()->create(['name' => 'Produto 2', 'stock_unit' => 'un', 'active' => true]),
            'P3' => Product::query()->create(['name' => 'Produto 3', 'stock_unit' => 'un', 'active' => true]),
        ];
        foreach ($this->products as $externalId => $product) {
            PdvProductMapping::query()->create(['pdv_connection_id' => $this->connection->id, 'external_product_id' => $externalId, 'external_name' => $product->name, 'product_id' => $product->id, 'status' => 'confirmed']);
        }
        $this->mapPayment('CASH', 'Dinheiro', 'cash');
        $this->mapPayment('PIX', 'Pix', 'pix');
    }

    public function test_import_plan_is_pure_and_split_import_is_atomic_and_idempotent(): void
    {
        $this->stockAll('10');
        $order = $this->order(
            [$this->item('I1', 'P1', '1', '20', '20'), $this->item('I2', 'P2', '2', '15', '30'), $this->item('I3', 'P3', '3', '10', '30')],
            [$this->payment('PAY-PIX', 'PIX', 'Pix', 'pix', '40'), $this->payment('PAY-CASH', 'CASH', 'Dinheiro', 'dinheiro', '40')],
            '80',
        );
        $before = $this->officialCounts();
        $plan = app(PdvOrderImportPlanService::class)->plan($order);
        $this->assertTrue($plan['ready']);
        $this->assertFalse($plan['import_enabled']);
        $this->assertCount(3, $plan['items']);
        $this->assertCount(2, $plan['payments']);
        $this->assertSame(['order_headers' => 1, 'product_sales' => 3, 'payments' => 2, 'stock_movements' => 3], $plan['planned_counts']);
        $this->assertSame('9.000000', $plan['stock_after'][0]['balance_after']);
        $this->assertSame($before, $this->officialCounts());

        config()->set('pdv.import_enabled', true);
        $first = app(PdvOrderImportService::class)->execute($order, $this->admin);
        $second = app(PdvOrderImportService::class)->execute($order->fresh(), $this->admin);
        $this->assertSame('imported', $first['status']);
        $this->assertSame('already_imported', $second['status']);
        $this->assertSame($first['order']->id, $second['order']->id);
        $this->assertDatabaseCount('product_sale_orders', 1);
        $this->assertDatabaseCount('product_sales', 3);
        $this->assertDatabaseCount('product_sale_payments', 2);
        $this->assertDatabaseCount('product_sale_payment_allocations', 6);
        $this->assertSame(['cash', 'pix'], ProductSalePayment::query()->orderBy('payment_method')->pluck('payment_method')->all());
        $this->assertSame('80.00', $this->sumMoney(ProductSalePayment::query()->pluck('amount')->all()));
        $this->assertSame('80.00', $this->sumMoney(ProductSalePayment::query()->pluck('net_amount')->all()));
        $this->assertSame('0.00', $this->sumMoney(ProductSalePayment::query()->pluck('fee_amount')->all()));
        $this->assertDatabaseHas('pdv_orders', ['id' => $order->id, 'processing_state' => PdvOrder::STATE_IMPORTED]);
        $this->assertSame('9.000000', app(StockBalanceService::class)->balance($this->products['P1'], $this->location));
        $this->assertSame('8.000000', app(StockBalanceService::class)->balance($this->products['P2'], $this->location));
        $this->assertSame('7.000000', app(StockBalanceService::class)->balance($this->products['P3'], $this->location));
        $event = PdvIntegrationEvent::query()->where('event_type', 'order_imported')->firstOrFail();
        $this->assertSame($this->admin->id, $event->user_id);
        $this->assertSame($this->location->id, $event->metadata['location_id']);
        $this->assertSame($order->external_order_id, $event->metadata['external_order_id']);
        $this->assertCount(3, $event->metadata['product_sale_ids']);
        $this->assertCount(2, $event->metadata['product_sale_payment_ids']);
        $this->assertCount(3, $event->metadata['stock_movement_ids']);
    }

    public function test_credit_plus_pix_calculates_percentage_and_fixed_fee_once_on_credit_payment(): void
    {
        $this->stock($this->products['P1'], '10');
        $this->mapCardPayment('CREDIT', 'Crédito', 'credit', '2.500000', '0.3000');
        $order = $this->order(
            [$this->item('I1', 'P1', '1', '100', '100')],
            [$this->payment('PAY-CARD', 'CREDIT', 'Crédito', 'credito', '60', installments: 2), $this->payment('PAY-PIX', 'PIX', 'Pix', 'pix', '40')],
            '100',
        );
        $plan = app(PdvOrderImportPlanService::class)->plan($order);
        $credit = collect($plan['payments'])->firstWhere('payment_method', 'credit');
        $pix = collect($plan['payments'])->firstWhere('payment_method', 'pix');
        $this->assertSame('1.80', $credit['fee_amount']);
        $this->assertSame('58.20', $credit['net_amount']);
        $this->assertSame('0.00', $pix['fee_amount']);
        $this->assertSame('40.00', $pix['net_amount']);

        config()->set('pdv.import_enabled', true);
        app(PdvOrderImportService::class)->execute($order, $this->admin);
        $this->assertDatabaseHas('product_sale_payments', ['payment_method' => 'credit', 'amount' => '60.00', 'fee_amount' => '1.80', 'net_amount' => '58.20', 'installments' => 2]);
        $this->assertSame('1.80', $this->sumMoney(ProductSalePaymentAllocation::query()->pluck('fee_allocated')->all()));
        $this->assertSame('1.80', ProductSale::query()->firstOrFail()->fee_amount_snapshot);
        $snapshot = ProductSalePayment::query()->where('payment_method', 'credit')->firstOrFail()->only(['payment_method', 'acquirer_id', 'card_brand_id', 'fee_percentage_snapshot', 'fixed_fee_snapshot', 'fee_amount']);
        PdvPaymentMethodMapping::query()->where('external_method_code', 'CREDIT')->update(['payment_method' => 'pix', 'acquirer_id' => null, 'card_brand_id' => null]);
        PaymentFee::query()->update(['fee_percentage' => '9.000000', 'fixed_fee' => '9.0000']);
        $this->assertSame($snapshot, ProductSalePayment::query()->where('payment_method', 'credit')->firstOrFail()->only(array_keys($snapshot)));
    }

    public function test_three_payments_are_preserved_without_a_principal_payment(): void
    {
        $this->stock($this->products['P1'], '10');
        $this->mapCardPayment('DEBIT', 'Débito', 'debit', '1.000000', '0');
        $order = $this->order(
            [$this->item('I1', 'P1', '3', '10', '30')],
            [$this->payment('P1', 'CASH', 'Dinheiro', 'dinheiro', '10'), $this->payment('P2', 'PIX', 'Pix', 'pix', '10'), $this->payment('P3', 'DEBIT', 'Débito', 'debito', '10')],
            '30',
        );
        config()->set('pdv.import_enabled', true);
        app(PdvOrderImportService::class)->execute($order, $this->admin);
        $this->assertDatabaseCount('product_sale_payments', 3);
        $this->assertSame(['cash', 'debit', 'pix'], ProductSalePayment::query()->orderBy('payment_method')->pluck('payment_method')->all());
        $this->assertSame('0.10', ProductSalePayment::query()->where('payment_method', 'debit')->firstOrFail()->fee_amount);
    }

    public function test_largest_remainder_and_header_discount_close_every_cent_exactly(): void
    {
        $allocator = app(MoneyAllocationService::class);
        $this->assertSame(['3.34', '3.33', '3.33'], $allocator->allocate('10.00', ['1', '1', '1'], ['A', 'B', 'C']));
        $this->assertSame(['0.01', '0.00', '0.00'], $allocator->allocate('0.01', ['1', '1', '1'], ['A', 'B', 'C']));

        $this->stockAll('10');
        $order = $this->order(
            [$this->item('I1', 'P1', '1', '3.34', '3.34'), $this->item('I2', 'P2', '1', '3.33', '3.33'), $this->item('I3', 'P3', '1', '3.33', '3.33')],
            [$this->payment('PAY-PIX', 'PIX', 'Pix', 'pix', '9.99')],
            '9.99',
            subtotal: '10.00',
            discount: '0.01',
        );
        $plan = app(PdvOrderImportPlanService::class)->plan($order);
        $this->assertTrue($plan['ready']);
        $this->assertSame('0.01', $plan['totals']['discount_allocated']);
        $this->assertSame('9.99', $plan['totals']['product_revenue']);
        $this->assertSame('9.99', $this->sumMoney(collect($plan['payments'][0]['allocations'])->pluck('gross_allocated')->all()));
        $this->assertSame('9.99', $this->sumMoney(collect($plan['payments'][0]['allocations'])->pluck('net_allocated')->all()));
        config()->set('pdv.import_enabled', true);
        app(PdvOrderImportService::class)->execute($order, $this->admin);
        $this->assertSame('0.01', $this->sumMoney(ProductSale::query()->pluck('discount_amount_snapshot')->all()));
        $this->assertDatabaseHas('product_sale_orders', ['discount_total_snapshot' => '0.01', 'total_amount_snapshot' => '9.99']);
    }

    public function test_cash_change_is_preserved_on_header_and_never_becomes_revenue(): void
    {
        $this->stock($this->products['P1'], '10');
        $order = $this->order(
            [$this->item('I1', 'P1', '1', '10', '10')],
            [$this->payment('PAY', 'CASH', 'Dinheiro', 'dinheiro', '20')],
            '10',
            change: '10',
        );
        $plan = app(PdvOrderImportPlanService::class)->plan($order);
        $this->assertTrue($plan['ready']);
        $this->assertSame('20.00', $plan['payments'][0]['external_amount']);
        $this->assertSame('10.00', $plan['payments'][0]['amount']);
        $this->assertSame('10.00', $plan['payments'][0]['allocations'][0]['gross_allocated']);
        config()->set('pdv.import_enabled', true);
        app(PdvOrderImportService::class)->execute($order, $this->admin);
        $this->assertDatabaseHas('product_sale_orders', ['paid_total_snapshot' => '20.00', 'change_total_snapshot' => '10.00', 'total_amount_snapshot' => '10.00']);
        $this->assertDatabaseHas('product_sale_payments', ['external_amount_snapshot' => '20.00', 'amount' => '10.00', 'net_amount' => '10.00']);
    }

    public function test_one_cent_payment_mismatch_is_blocked_before_any_official_write(): void
    {
        $this->stock($this->products['P1'], '10');
        $order = $this->order(
            [$this->item('I1', 'P1', '1', '10', '10')],
            [$this->payment('PAY', 'CASH', 'Dinheiro', 'dinheiro', '9.99')],
            '10',
        );

        $plan = app(PdvOrderImportPlanService::class)->plan($order);
        $this->assertContains('official_payment_total_mismatch', collect($plan['blockers'])->pluck('code'));
        $this->assertFalse($plan['ready']);

        config()->set('pdv.import_enabled', true);
        try {
            app(PdvOrderImportService::class)->execute($order, $this->admin);
            $this->fail('A divergência de um centavo deveria impedir a importação oficial.');
        } catch (PdvOrderImportBlockedException) {
            $this->assertDatabaseCount('product_sale_orders', 0);
            $this->assertDatabaseCount('product_sales', 0);
            $this->assertDatabaseCount('product_sale_payments', 0);
            $this->assertDatabaseCount('product_sale_payment_allocations', 0);
            $this->assertDatabaseCount('stock_movements', 1);
        }
    }

    public function test_stock_is_consolidated_when_multiple_external_lines_map_to_the_same_product(): void
    {
        PdvProductMapping::query()->where('external_product_id', 'P2')->update(['product_id' => $this->products['P1']->id]);
        $this->stock($this->products['P1'], '5');
        $order = $this->order(
            [$this->item('I1', 'P1', '3', '10', '30'), $this->item('I2', 'P2', '2', '10', '20')],
            [$this->payment('PAY', 'CASH', 'Dinheiro', 'dinheiro', '50')],
            '50',
        );
        $plan = app(PdvOrderImportPlanService::class)->plan($order);
        $this->assertTrue($plan['ready']);
        $this->assertSame('5.000000', $plan['reconciliation']['stock_status']['products'][0]['required']);
        config()->set('pdv.import_enabled', true);
        app(PdvOrderImportService::class)->execute($order, $this->admin);
        $this->assertSame('0.000000', app(StockBalanceService::class)->balance($this->products['P1'], $this->location));
        $this->assertDatabaseCount('product_sales', 2);
    }

    public function test_insufficient_stock_or_inactive_product_has_zero_operational_effects(): void
    {
        PdvProductMapping::query()->where('external_product_id', 'P2')->update(['product_id' => $this->products['P1']->id]);
        $this->stock($this->products['P1'], '4');
        $order = $this->order(
            [$this->item('I1', 'P1', '3', '10', '30'), $this->item('I2', 'P2', '2', '10', '20')],
            [$this->payment('PAY', 'CASH', 'Dinheiro', 'dinheiro', '50')],
            '50',
        );
        $this->assertContains('stock_insufficient', collect(app(PdvOrderImportPlanService::class)->plan($order)['blockers'])->pluck('code'));
        config()->set('pdv.import_enabled', true);
        try {
            app(PdvOrderImportService::class)->execute($order, $this->admin);
            $this->fail('A importação deveria ter sido bloqueada.');
        } catch (PdvOrderImportBlockedException) {
            $this->assertDatabaseCount('product_sale_orders', 0);
            $this->assertDatabaseCount('product_sales', 0);
            $this->assertDatabaseCount('product_sale_payments', 0);
            $this->assertDatabaseCount('stock_movements', 1);
        }

        $this->products['P1']->update(['active' => false]);
        $this->assertContains('mapped_product_inactive', collect(app(PdvOrderImportPlanService::class)->plan($order->fresh())['blockers'])->pluck('code'));
    }

    public function test_failure_after_first_item_rolls_back_header_sales_payments_and_stock(): void
    {
        $this->stockAll('10');
        $order = $this->order(
            [$this->item('I1', 'P1', '1', '10', '10'), $this->item('I2', 'P2', '1', '10', '10')],
            [$this->payment('PAY', 'CASH', 'Dinheiro', 'dinheiro', '20')],
            '20',
        );
        $beforeMovements = StockMovement::query()->count();
        $real = app(ProductSaleService::class);
        $calls = 0;
        $mock = Mockery::mock($real)->makePartial();
        $mock->shouldReceive('recordPdvItem')->andReturnUsing(function (array $data, User $user) use (&$calls, $real) {
            $calls++;
            if ($calls === 2) {
                throw new DomainException('Falha controlada após o primeiro item.');
            }

            return $real->recordPdvItem($data, $user);
        });
        $this->app->instance(ProductSaleService::class, $mock);
        config()->set('pdv.import_enabled', true);

        try {
            app(PdvOrderImportService::class)->execute($order, $this->admin);
            $this->fail('A falha controlada deveria propagar.');
        } catch (DomainException $exception) {
            $this->assertSame('Falha controlada após o primeiro item.', $exception->getMessage());
        }
        $this->assertDatabaseCount('product_sale_orders', 0);
        $this->assertDatabaseCount('product_sales', 0);
        $this->assertDatabaseCount('product_sale_payments', 0);
        $this->assertSame($beforeMovements, StockMovement::query()->count());
        $this->assertDatabaseHas('pdv_orders', ['id' => $order->id, 'processing_state' => PdvOrder::STATE_STAGED]);
    }

    public function test_cancelled_unimported_order_is_recognized_before_mappings(): void
    {
        PdvProductMapping::query()->delete();
        PdvPaymentMethodMapping::query()->delete();
        $order = $this->order([$this->item('I1', 'P1', '1', '10', '10')], [$this->payment('PAY', 'CASH', 'Dinheiro', 'dinheiro', '10')], '10', status: 'cancelled');
        $codes = collect(app(PdvOrderImportPlanService::class)->plan($order)['blockers'])->pluck('code');
        $this->assertContains('order_cancelled', $codes);
        $this->assertNotContains('product_mapping_missing', $codes);
        $this->assertNotContains('payment_mapping_missing', $codes);
        $this->assertDatabaseCount('product_sale_orders', 0);
    }

    public function test_reversal_preserves_originals_restores_stock_and_is_idempotent(): void
    {
        $this->stock($this->products['P1'], '10');
        $order = $this->order([$this->item('I1', 'P1', '2', '10', '20')], [$this->payment('PAY', 'CASH', 'Dinheiro', 'dinheiro', '20')], '20');
        config()->set('pdv.import_enabled', true);
        $imported = app(PdvOrderImportService::class)->execute($order, $this->admin)['order'];
        $this->assertSame('8.000000', app(StockBalanceService::class)->balance($this->products['P1'], $this->location));
        $order->update(['external_status' => 'cancelled', 'latest_source_hash' => str_repeat('b', 64), 'source_changed_at' => now()]);
        $this->assertContains('source_changed_after_import', collect(app(PdvOrderImportPlanService::class)->plan($order->fresh())['blockers'])->pluck('code'));

        $first = app(PdvOrderReversalService::class)->reverse($order->fresh(), $this->admin, 'Cancelamento de teste');
        $paymentCount = ProductSalePayment::query()->count();
        $movementCount = StockMovement::query()->count();
        $second = app(PdvOrderReversalService::class)->reverse($order->fresh(), $this->admin, 'Cancelamento de teste');
        $this->assertSame($first->id, $second->id);
        $this->assertSame(ProductSaleOrder::STATUS_REVERSED, $second->status);
        $this->assertSame('10.000000', app(StockBalanceService::class)->balance($this->products['P1'], $this->location));
        $this->assertSame($paymentCount, ProductSalePayment::query()->count());
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertDatabaseCount('product_sales', 1);
        $this->assertDatabaseHas('product_sales', ['product_sale_order_id' => $imported->id, 'external_status' => 'cancelled']);
        $this->assertDatabaseCount('product_sale_payments', 2);
        $this->assertDatabaseHas('product_sale_payments', ['reversal_of_id' => ProductSalePayment::query()->whereNull('reversal_of_id')->value('id'), 'amount' => '-20.00']);
        $financial = app(PaymentFeeReportService::class)->summarize($this->location, '2026-08-20', '2026-08-20');
        $this->assertSame('0', $financial['gross']);
        $this->assertSame('0', $financial['net']);
        $event = PdvIntegrationEvent::query()->where('event_type', 'order_reversed')->firstOrFail();
        $this->assertSame($this->admin->id, $event->user_id);
        $this->assertSame($this->location->id, $event->metadata['location_id']);
        $this->assertSame('Cancelamento de teste', $event->metadata['reason']);
        $this->assertCount(1, $event->metadata['reversal_payment_ids']);
        $this->assertCount(1, $event->metadata['reversal_stock_movement_ids']);
    }

    public function test_reports_use_items_for_product_revenue_and_payments_for_financial_totals_without_doubling(): void
    {
        $this->stockAll('10');
        $order = $this->order(
            [$this->item('I1', 'P1', '1', '20', '20'), $this->item('I2', 'P2', '1', '30', '30'), $this->item('I3', 'P3', '1', '30', '30')],
            [$this->payment('P1', 'CASH', 'Dinheiro', 'dinheiro', '40'), $this->payment('P2', 'PIX', 'Pix', 'pix', '40')],
            '80',
        );
        config()->set('pdv.import_enabled', true);
        app(PdvOrderImportService::class)->execute($order, $this->admin);
        $sales = app(SalesSummaryService::class)->summarize($this->location, '2026-08-20', '2026-08-20');
        $financial = app(PaymentFeeReportService::class)->summarize($this->location, '2026-08-20', '2026-08-20');
        $this->assertSame('80', $sales['revenue']);
        $this->assertSame('80', $financial['gross']);
        $this->assertSame('0', $financial['fees']);
        $this->assertSame('80', $financial['net']);
        $byMethod = $financial['by_method']->keyBy('payment_method');
        $this->assertSame('40', $byMethod['cash']->gross);
        $this->assertSame('40', $byMethod['pix']->gross);
    }

    public function test_feature_flag_and_location_permission_block_the_controller_and_backend(): void
    {
        $order = $this->order([$this->item('I1', 'P1', '1', '10', '10')], [$this->payment('PAY', 'CASH', 'Dinheiro', 'dinheiro', '10')], '10');
        $this->actingAs($this->admin)->get(route('pdv.staging.show', [$this->connection, $order]))
            ->assertOk()->assertSee('Importação operacional desabilitada')->assertSee('PDV_IMPORT_ENABLED=false', false);
        $this->actingAs($this->admin)->post(route('pdv.staging.import', [$this->connection, $order]), ['confirmed' => 1, 'single_order_confirmed' => 1, 'confirmation_text' => 'IMPORTAR'])
            ->assertRedirect()->assertSessionHas('error', 'A importação operacional de PDV está desabilitada.');
        $this->expectException(IntegrationNotConfiguredException::class);
        app(PdvOrderImportService::class)->execute($order, $this->admin);
    }

    public function test_first_go_live_batch_guard_rejects_more_than_one_order_before_any_write(): void
    {
        $first = $this->order([$this->item('I1', 'P1', '1', '10', '10')], [$this->payment('PAY', 'CASH', 'Dinheiro', 'dinheiro', '10')], '10');
        $second = $first->replicate();
        $second->external_order_id = 'ORDER-TEST-2';
        $second->external_code = '1002';
        $second->save();
        config()->set('pdv.first_import_single_order', true);
        config()->set('pdv.import_enabled', true);

        try {
            app(PdvOrderBatchImportService::class)->execute(collect([$first, $second]), $this->admin);
            $this->fail('O lote inicial com mais de um pedido deveria ser bloqueado.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('somente um pedido', $exception->getMessage());
        }
        $this->assertDatabaseCount('product_sale_orders', 0);
        $this->assertDatabaseCount('product_sales', 0);
    }

    public function test_import_route_requires_two_checkboxes_and_exact_confirmation_text(): void
    {
        $order = $this->order([$this->item('I1', 'P1', '1', '10', '10')], [$this->payment('PAY', 'CASH', 'Dinheiro', 'dinheiro', '10')], '10');
        config()->set('pdv.import_enabled', true);

        $this->actingAs($this->admin)->post(route('pdv.staging.import', [$this->connection, $order]), ['confirmed' => 1, 'confirmation_text' => 'importar'])
            ->assertSessionHasErrors(['single_order_confirmed', 'confirmation_text']);
        $this->assertDatabaseCount('product_sale_orders', 0);
        $this->assertDatabaseCount('product_sales', 0);
    }

    public function test_cross_location_user_cannot_preview_or_import(): void
    {
        $order = $this->order([$this->item('I1', 'P1', '1', '10', '10')], [$this->payment('PAY', 'CASH', 'Dinheiro', 'dinheiro', '10')], '10');
        $restricted = User::factory()->unprivileged()->create();
        $restricted->permissions()->attach(Permission::query()->where('name', 'pdv.manage')->firstOrFail(), ['allowed' => true]);
        $restricted->locations()->attach($this->otherLocation->id);
        $this->actingAs($restricted)->get(route('pdv.staging.show', [$this->connection, $order]))->assertForbidden();
        $this->actingAs($restricted)->post(route('pdv.staging.import', [$this->connection, $order]), ['confirmed' => 1, 'single_order_confirmed' => 1, 'confirmation_text' => 'IMPORTAR'])->assertForbidden();
        $this->assertDatabaseCount('product_sale_orders', 0);
    }

    /** @param array<int,array<string,mixed>> $items @param array<int,array<string,mixed>> $payments */
    private function order(array $items, array $payments, string $total, ?string $subtotal = null, string $discount = '0', string $service = '0', string $delivery = '0', string $change = '0', string $status = 'concluido'): PdvOrder
    {
        $order = PdvOrder::query()->create([
            'pdv_connection_id' => $this->connection->id,
            'location_id' => $this->location->id,
            'external_order_id' => 'ORDER-TEST',
            'external_code' => '1001',
            'external_status' => $status,
            'quantity' => '1',
            'service_total' => $service,
            'delivery_total' => $delivery,
            'subtotal' => $subtotal ?? $total,
            'discount_total' => $discount,
            'total' => $total,
            'paid_total' => $this->sumMoney(array_column($payments, 'amount')),
            'change_total' => $change,
            'external_created_at' => '2026-08-20 15:00:00-03:00',
            'external_completed_at' => '2026-08-20 15:10:00-03:00',
            'external_updated_at' => '2026-08-20 15:10:00-03:00',
            'source_hash' => str_repeat('a', 64),
            'latest_source_hash' => str_repeat('a', 64),
            'processing_state' => PdvOrder::STATE_STAGED,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        foreach ($items as $item) {
            PdvOrderItem::query()->create(array_merge($item, ['pdv_order_id' => $order->id, 'external_status' => 'concluido', 'cancelled' => false, 'present_in_latest' => true, 'first_seen_at' => now(), 'last_seen_at' => now()]));
        }
        foreach ($payments as $payment) {
            PdvOrderPayment::query()->create(array_merge($payment, ['pdv_order_id' => $order->id, 'external_status' => 'pago', 'present_in_latest' => true, 'first_seen_at' => now(), 'last_seen_at' => now()]));
        }

        return $order->load(['connection', 'location', 'items', 'payments']);
    }

    /** @return array<string,mixed> */
    private function item(string $id, string $product, string $quantity, string $unitPrice, string $total): array
    {
        return ['external_item_id' => $id, 'external_product_id' => $product, 'external_product_code' => $product, 'description' => "Produto {$product}", 'quantity' => $quantity, 'unit_price' => $unitPrice, 'subtotal' => $total, 'total' => $total];
    }

    /** @return array<string,mixed> */
    private function payment(string $id, string $form, string $description, string $type, string $amount, ?int $installments = null): array
    {
        return ['external_payment_id' => $id, 'external_form_id' => $form, 'external_form_description' => $description, 'external_type' => $type, 'amount' => $amount, 'external_total' => $amount, 'fees' => '0', 'installment_number' => $installments === null ? null : 1, 'installments' => $installments];
    }

    private function mapPayment(string $external, string $name, string $method, ?Acquirer $acquirer = null, ?CardBrand $brand = null): void
    {
        PdvPaymentMethodMapping::query()->create(['pdv_connection_id' => $this->connection->id, 'external_method_code' => $external, 'external_name' => $name, 'payment_method' => $method, 'acquirer_id' => $acquirer?->id, 'card_brand_id' => $brand?->id, 'status' => 'confirmed']);
    }

    private function mapCardPayment(string $external, string $name, string $method, string $percentage, string $fixed): void
    {
        $acquirer = Acquirer::query()->create(['name' => "Adquirente {$method}", 'active' => true]);
        $brand = CardBrand::query()->create(['name' => "Bandeira {$method}", 'active' => true]);
        PaymentFee::query()->create(['acquirer_id' => $acquirer->id, 'card_brand_id' => $brand->id, 'payment_method' => $method, 'fee_percentage' => $percentage, 'fixed_fee' => $fixed, 'effective_from' => '2026-01-01', 'is_current' => true, 'active' => true, 'source' => 'test']);
        $this->mapPayment($external, $name, $method, $acquirer, $brand);
    }

    private function stockAll(string $quantity): void
    {
        foreach ($this->products as $product) {
            $this->stock($product, $quantity);
        }
    }

    private function stock(Product $product, string $quantity): void
    {
        app(StockMovementService::class)->record(new RecordStockMovementData($product->id, $this->location->id, StockMovementType::OpeningBalance, $quantity, '2026-08-19', "opening:{$product->id}"));
    }

    /** @return array<string,int> */
    private function officialCounts(): array
    {
        return [
            'orders' => ProductSaleOrder::query()->count(),
            'sales' => ProductSale::query()->count(),
            'payments' => ProductSalePayment::query()->count(),
            'allocations' => ProductSalePaymentAllocation::query()->count(),
            'movements' => StockMovement::query()->count(),
        ];
    }

    /** @param array<int,string> $values */
    private function sumMoney(array $values): string
    {
        return (string) array_reduce($values, fn (BigDecimal $sum, string $value): BigDecimal => $sum->plus($value), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp);
    }
}
