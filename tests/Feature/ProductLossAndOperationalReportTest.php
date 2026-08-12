<?php

namespace Tests\Feature;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\LossReason;
use App\Models\Product;
use App\Models\ProductLoss;
use App\Models\User;
use App\Services\OperationalSummaryService;
use App\Services\StockBalanceService;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductLossAndOperationalReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_loss_routes_are_protected(): void
    {
        $this->get(route('losses.index'))->assertRedirect(route('login'));
        $this->get(route('losses.create'))->assertRedirect(route('login'));
        $this->get(route('loss-reasons.index'))->assertRedirect(route('login'));
    }

    public function test_loss_reduces_stock_once_and_preserves_user_reason_and_reference(): void
    {
        [$user, $product, $location, $reason] = $this->catalog('20');
        $key = (string) Str::uuid();
        $payload = [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'loss_reason_id' => $reason->id,
            'quantity' => '2',
            'operation_date' => '2026-08-07',
            'idempotency_key' => $key,
            'notes' => 'Produto danificado.',
        ];

        $this->actingAs($user)->post(route('losses.store'), $payload)->assertRedirect(route('losses.index'));
        $this->post(route('losses.store'), $payload)->assertRedirect(route('losses.index'));

        $loss = ProductLoss::query()->firstOrFail();
        $this->assertSame('18.000000', app(StockBalanceService::class)->balance($product, $location));
        $this->assertDatabaseCount('product_losses', 1);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('product_losses', ['created_by' => $user->id, 'loss_reason_id' => $reason->id]);
        $this->assertDatabaseHas('stock_movements', [
            'type' => StockMovementType::Loss->value,
            'reference_type' => ProductLoss::class,
            'reference_id' => (string) $loss->id,
        ]);
    }

    public function test_loss_above_available_stock_is_rejected_atomically(): void
    {
        [$user, $product, $location, $reason] = $this->catalog('1');

        $this->actingAs($user)->post(route('losses.store'), [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'loss_reason_id' => $reason->id,
            'quantity' => '2',
            'operation_date' => '2026-08-08',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('quantity');

        $this->assertDatabaseCount('product_losses', 0);
        $this->assertSame('1.000000', app(StockBalanceService::class)->balance($product, $location));
    }

    public function test_loss_idempotency_key_rejects_a_different_payload(): void
    {
        [$user, $product, $location, $reason] = $this->catalog('10');
        $key = (string) Str::uuid();
        $base = [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'loss_reason_id' => $reason->id,
            'operation_date' => '2026-08-08',
            'idempotency_key' => $key,
        ];

        $this->actingAs($user)->post(route('losses.store'), [...$base, 'quantity' => '1']);
        $this->post(route('losses.store'), [...$base, 'quantity' => '2'])->assertSessionHasErrors('quantity');

        $this->assertSame('9.000000', app(StockBalanceService::class)->balance($product, $location));
        $this->assertDatabaseCount('product_losses', 1);
    }

    public function test_operational_summary_reads_the_official_ledger_for_the_period(): void
    {
        [$user, $product, $location] = $this->catalog('10');
        $this->movement($product, $location, StockMovementType::Production, '20', 'production');
        $this->movement($product, $location, StockMovementType::TransferOut, '-5', 'transfer');
        $this->movement($product, $location, StockMovementType::Loss, '-2', 'loss');
        $this->movement($product, $location, StockMovementType::Adjustment, '1', 'adjustment');

        $summary = app(OperationalSummaryService::class)->summarize($location, '2026-08-01', '2026-08-31');

        $this->assertSame('20.000000', $summary['production']['un']);
        $this->assertSame('10.000000', $summary['entries']['un']);
        $this->assertSame('5.000000', $summary['transfers']['un']);
        $this->assertSame('2.000000', $summary['losses']['un']);
        $this->assertSame('1.000000', $summary['adjustments']['un']);
    }

    /** @return array{User, Product, Location, LossReason} */
    private function catalog(string $stock): array
    {
        $user = User::factory()->create();
        $product = Product::query()->create(['name' => 'Costela', 'stock_unit' => 'un', 'active' => true]);
        $location = Location::query()->create(['name' => 'Fábrica', 'type' => 'production', 'active' => true]);
        $reason = LossReason::query()->create(['name' => 'Danificado', 'active' => true]);
        $this->movement($product, $location, StockMovementType::OpeningBalance, $stock, 'opening');

        return [$user, $product, $location, $reason];
    }

    private function movement(Product $product, Location $location, StockMovementType $type, string $quantity, string $key): void
    {
        app(StockMovementService::class)->record(new RecordStockMovementData(
            productId: $product->id,
            locationId: $location->id,
            type: $type,
            quantityDelta: $quantity,
            operationDate: '2026-08-08',
            idempotencyKey: $key,
        ));
    }
}
