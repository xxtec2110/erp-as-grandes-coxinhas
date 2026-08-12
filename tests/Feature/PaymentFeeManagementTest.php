<?php

namespace Tests\Feature;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Acquirer;
use App\Models\CardBrand;
use App\Models\Location;
use App\Models\PaymentFee;
use App\Models\PaymentFeeImport;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\User;
use App\Services\PaymentFeeImportService;
use App\Services\PaymentFeeReportService;
use App\Services\PaymentFeeResolver;
use App\Services\PaymentFeeService;
use App\Services\StockMovementService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentFeeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Acquirer $acquirer;

    protected CardBrand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $this->acquirer = Acquirer::query()->create(['name' => 'Stone', 'active' => true]);
        $this->brand = CardBrand::query()->create(['name' => 'Visa', 'active' => true]);
    }

    public function test_fee_is_resolved_by_acquirer_brand_method_installments_and_validity(): void
    {
        $fee = $this->fee('debit', '1.290000', '2026-08-01');
        $this->assertTrue($fee->is(app(PaymentFeeResolver::class)->resolve($this->acquirer->id, $this->brand->id, 'debit', null, '2026-08-08')));
        $this->assertNull(app(PaymentFeeResolver::class)->resolve($this->acquirer->id, $this->brand->id, 'credit', null, '2026-08-08'));
        $this->assertNull(app(PaymentFeeResolver::class)->resolve($this->acquirer->id, $this->brand->id, 'debit', null, '2026-07-31'));
    }

    public function test_new_fee_preserves_history_and_audit(): void
    {
        $old = $this->fee('credit', '2.800000', '2026-08-01');
        $new = $this->fee('credit', '2.990000', '2026-08-10');

        $this->assertFalse($old->refresh()->is_current);
        $this->assertSame('2026-08-09', $old->effective_until->toDateString());
        $this->assertTrue($new->is_current);
        $this->assertDatabaseCount('payment_fee_audits', 2);
        $this->assertDatabaseHas('payment_fee_audits', ['payment_fee_id' => $new->id, 'user_id' => $this->admin->id]);
    }

    public function test_sale_keeps_fee_snapshot_after_current_fee_changes(): void
    {
        $this->fee('debit', '1.290000', '2026-08-01', '0.10');
        [$location, $product] = $this->stock();
        $payload = ['location_id' => $location->id, 'product_id' => $product->id, 'quantity' => '10', 'unit_price' => '10', 'payment_method' => 'debit', 'acquirer_id' => $this->acquirer->id, 'card_brand_id' => $this->brand->id, 'operation_date' => '2026-08-08', 'idempotency_key' => (string) Str::uuid()];
        $this->actingAs($this->admin)->post(route('sales.store'), $payload)->assertRedirect(route('sales.index'));
        $sale = ProductSale::query()->firstOrFail();

        $this->assertSame('1.290000', $sale->fee_percentage_snapshot);
        $this->assertSame('1.39', $sale->fee_amount_snapshot);
        $this->assertSame('98.61', $sale->net_amount);
        $this->fee('debit', '2.000000', '2026-08-10');
        $this->assertSame('1.290000', $sale->refresh()->fee_percentage_snapshot);
        $this->assertSame('98.61', $sale->net_amount);
    }

    public function test_batch_preview_confirmation_is_transactional_audited_and_idempotent(): void
    {
        $service = app(PaymentFeeImportService::class);
        $rows = [$this->row($this->brand->id, 'debit', '1.29'), $this->row($this->brand->id, 'credit', '2.99')];
        $key = (string) Str::uuid();
        $import = $service->preview($rows, $this->admin, $key);
        $this->assertSame(PaymentFeeImport::AWAITING_CONFIRMATION, $import->status);
        $this->assertDatabaseCount('payment_fees', 0);

        $service->confirm($import, $this->admin);
        $service->confirm($import->refresh(), $this->admin);
        $this->assertDatabaseCount('payment_fees', 2);
        $this->assertDatabaseCount('payment_fee_audits', 2);
        $this->assertSame(PaymentFeeImport::APPLIED, $import->refresh()->status);
        $this->assertSame($import->id, $service->preview($rows, $this->admin, $key)->id);
    }

    public function test_rejected_preview_never_changes_fees(): void
    {
        $service = app(PaymentFeeImportService::class);
        $import = $service->preview([$this->row($this->brand->id, 'debit', '1.29')], $this->admin, (string) Str::uuid());
        $service->reject($import, $this->admin);
        $this->assertSame(PaymentFeeImport::REJECTED, $import->refresh()->status);
        $this->assertDatabaseCount('payment_fees', 0);
    }

    public function test_critical_batch_failure_rolls_back_every_row(): void
    {
        $service = app(PaymentFeeImportService::class);
        $import = $service->preview([$this->row($this->brand->id, 'debit', '1.29'), $this->row(999999, 'credit', '2.99')], $this->admin, (string) Str::uuid());

        try {
            $service->confirm($import, $this->admin);
            $this->fail('O lote inválido deveria falhar.');
        } catch (QueryException) {
            $this->assertDatabaseCount('payment_fees', 0);
            $this->assertDatabaseCount('payment_fee_audits', 0);
            $this->assertSame(PaymentFeeImport::AWAITING_CONFIRMATION, $import->refresh()->status);
        }
    }

    public function test_installment_specific_fee_takes_precedence_over_generic_credit_fee(): void
    {
        $this->fee('credit', '2.990000', '2026-08-01');
        app(PaymentFeeService::class)->apply([...$this->row($this->brand->id, 'credit', '3.490000', '2026-08-01'), 'installments' => 2], $this->admin);
        $resolved = app(PaymentFeeResolver::class)->resolve($this->acquirer->id, $this->brand->id, 'credit', 2, '2026-08-08');
        $this->assertSame('3.490000', $resolved?->fee_percentage);
    }

    public function test_unauthorized_user_cannot_view_or_import_fees(): void
    {
        $user = User::factory()->unprivileged()->create();
        $this->actingAs($user)->get(route('payment-fees.index'))->assertForbidden();
        $this->get(route('payment-fees.batch'))->assertForbidden();
    }

    public function test_report_summarizes_gross_fees_and_net_by_location(): void
    {
        $this->fee('debit', '2.000000', '2026-08-01');
        [$location, $product] = $this->stock();
        $this->actingAs($this->admin)->post(route('sales.store'), ['location_id' => $location->id, 'product_id' => $product->id, 'quantity' => '10', 'unit_price' => '10', 'payment_method' => 'debit', 'acquirer_id' => $this->acquirer->id, 'card_brand_id' => $this->brand->id, 'operation_date' => '2026-08-08', 'idempotency_key' => (string) Str::uuid()]);
        $summary = app(PaymentFeeReportService::class)->summarize($location, '2026-08-01', '2026-08-31');
        $this->assertSame('100', $summary['gross']);
        $this->assertSame('2', $summary['fees']);
        $this->assertSame('98', $summary['net']);
        $this->assertCount(1, $summary['by_acquirer']);
    }

    private function fee(string $method, string $percentage, string $date, string $fixed = '0'): PaymentFee
    {
        return app(PaymentFeeService::class)->apply($this->row($this->brand->id, $method, $percentage, $date, $fixed), $this->admin);
    }

    /** @return array<string, mixed> */
    private function row(int $brandId, string $method, string $percentage, string $date = '2026-08-08', string $fixed = '0'): array
    {
        return ['acquirer_id' => $this->acquirer->id, 'card_brand_id' => $brandId, 'payment_method' => $method, 'installments' => null, 'fee_percentage' => $percentage, 'fixed_fee' => $fixed, 'effective_from' => $date, 'notes' => null];
    }

    /** @return array{Location, Product} */
    private function stock(): array
    {
        $location = Location::query()->create(['name' => 'Loja Ibirá', 'type' => 'store', 'active' => true]);
        $product = Product::query()->create(['name' => 'Coxinha', 'stock_unit' => 'un', 'active' => true]);
        app(StockMovementService::class)->record(new RecordStockMovementData($product->id, $location->id, StockMovementType::OpeningBalance, '100', '2026-08-01', (string) Str::uuid()));

        return [$location, $product];
    }
}
