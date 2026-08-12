<?php

namespace Tests\Feature;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Enums\StockTransferStatus;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\StockBalanceService;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockTransferManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_routes_are_protected(): void
    {
        $this->get(route('transfers.index'))->assertRedirect(route('login'));
        $this->get(route('transfers.create'))->assertRedirect(route('login'));
    }

    public function test_dispatch_and_receipt_move_stock_only_at_the_correct_stage(): void
    {
        [$user, $product, $source, $destination] = $this->catalogWithStock('100');

        $this->actingAs($user)->post(route('transfers.store'), [
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'product_id' => $product->id,
            'quantity' => '40',
            'operation_date' => '2026-08-08',
            'idempotency_key' => (string) Str::uuid(),
            'notes' => 'Reposição da loja.',
        ])->assertRedirect();

        $transfer = StockTransfer::query()->with('items')->firstOrFail();
        $balances = app(StockBalanceService::class);

        $this->assertSame('100.000000', $balances->balance($product, $source));
        $this->assertSame('0.000000', $balances->balance($product, $destination));

        $this->post(route('transfers.dispatch', $transfer), ['dispatched_date' => '2026-08-08'])
            ->assertRedirect(route('transfers.show', $transfer));
        $this->post(route('transfers.dispatch', $transfer), ['dispatched_date' => '2026-08-08'])
            ->assertRedirect(route('transfers.show', $transfer));

        $this->assertSame('60.000000', $balances->balance($product, $source));
        $this->assertSame('0.000000', $balances->balance($product, $destination));
        $this->assertDatabaseCount('stock_movements', 2);

        $item = $transfer->items->sole();
        $receipt = [
            'received_date' => '2026-08-09',
            'received_quantities' => [$item->id => '38'],
        ];
        $this->post(route('transfers.receive', $transfer), $receipt)
            ->assertRedirect(route('transfers.show', $transfer));
        $this->post(route('transfers.receive', $transfer), $receipt)
            ->assertRedirect(route('transfers.show', $transfer));

        $this->assertSame('60.000000', $balances->balance($product, $source));
        $this->assertSame('38.000000', $balances->balance($product, $destination));
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertSame(StockTransferStatus::Received, $transfer->fresh()->status);
        $this->assertSame('38.000000', $item->fresh()->quantity_received);
    }

    public function test_insufficient_stock_rolls_back_the_entire_dispatch(): void
    {
        [$user, $product, $source, $destination] = $this->catalogWithStock('10');
        $transfer = $this->createTransfer($user, $product, $source, $destination, '20');

        $this->actingAs($user)->post(route('transfers.dispatch', $transfer), [
            'dispatched_date' => '2026-08-08',
        ])->assertSessionHasErrors('transfer');

        $this->assertSame(StockTransferStatus::Pending, $transfer->fresh()->status);
        $this->assertSame('10.000000', app(StockBalanceService::class)->balance($product, $source));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_pending_transfer_can_be_cancelled_without_stock_movement(): void
    {
        [$user, $product, $source, $destination] = $this->catalogWithStock('10');
        $transfer = $this->createTransfer($user, $product, $source, $destination, '5');

        $this->actingAs($user)->post(route('transfers.cancel', $transfer))
            ->assertRedirect(route('transfers.show', $transfer));

        $this->assertSame(StockTransferStatus::Cancelled, $transfer->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    /** @return array{User, Product, Location, Location} */
    private function catalogWithStock(string $quantity): array
    {
        $user = User::factory()->create();
        $product = Product::query()->create(['name' => 'Costela', 'stock_unit' => 'un', 'active' => true]);
        $source = Location::query()->create(['name' => 'Fábrica', 'type' => 'production', 'active' => true]);
        $destination = Location::query()->create(['name' => 'Loja', 'type' => 'store', 'active' => true]);

        app(StockMovementService::class)->record(new RecordStockMovementData(
            productId: $product->id,
            locationId: $source->id,
            type: StockMovementType::OpeningBalance,
            quantityDelta: $quantity,
            operationDate: '2026-08-07',
            idempotencyKey: (string) Str::uuid(),
            createdBy: $user->id,
        ));

        return [$user, $product, $source, $destination];
    }

    private function createTransfer(
        User $user,
        Product $product,
        Location $source,
        Location $destination,
        string $quantity,
    ): StockTransfer {
        $this->actingAs($user)->post(route('transfers.store'), [
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'operation_date' => '2026-08-08',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        return StockTransfer::query()->firstOrFail();
    }
}
