<?php

namespace Tests\Feature;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\ProductSaleService;
use App\Services\StockMovementService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductSalePixTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create(['is_super_admin' => true]);
        $this->location = Location::query()->create(['name' => 'Loja teste', 'type' => Location::TYPE_STORE, 'active' => true]);
        $category = ProductCategory::query()->create(['name' => 'Coxinhas', 'active' => true]);
        $this->product = Product::query()->create(['name' => 'Frango', 'product_category_id' => $category->id, 'stock_unit' => 'un', 'active' => true]);
        app(StockMovementService::class)->record(new RecordStockMovementData($this->product->id, $this->location->id, StockMovementType::OpeningBalance, '10', '2026-08-20', 'pix-test-opening'));
    }

    public function test_pix_is_persisted_as_its_own_method_without_card_or_fee_snapshot(): void
    {
        $sale = app(ProductSaleService::class)->record($this->payload('pix'), $this->admin);

        $this->assertSame('pix', $sale->payment_method);
        $this->assertNull($sale->acquirer_id);
        $this->assertNull($sale->card_brand_id);
        $this->assertNull($sale->payment_fee_id);
        $this->assertSame('0.00', $sale->fee_amount_snapshot);
        $this->assertSame($sale->gross_amount, $sale->net_amount);
    }

    public function test_pix_validation_does_not_require_card_and_rejects_artificial_card_fields(): void
    {
        $this->actingAs($this->admin)->post(route('sales.store'), $this->payload('pix'))->assertRedirect(route('sales.index'));
        $this->actingAs($this->admin)->post(route('sales.store'), [...$this->payload('pix'), 'acquirer_id' => 999, 'card_brand_id' => 999])->assertSessionHasErrors(['acquirer_id', 'card_brand_id']);
    }

    public function test_sales_display_and_filter_distinguish_pix_from_cash(): void
    {
        app(ProductSaleService::class)->record($this->payload('pix'), $this->admin);
        app(ProductSaleService::class)->record($this->payload('cash'), $this->admin);

        $this->actingAs($this->admin)->get(route('sales.index', ['location_id' => $this->location->id, 'payment_method' => 'pix']))
            ->assertOk()->assertViewHas('sales', fn ($sales): bool => $sales->total() === 1 && $sales->first()->payment_method === 'pix')->assertSee('Pix');
        $this->assertDatabaseHas('product_sales', ['payment_method' => 'cash']);
        $this->assertDatabaseHas('product_sales', ['payment_method' => 'pix']);
    }

    /** @return array<string,mixed> */
    private function payload(string $method): array
    {
        return ['location_id' => $this->location->id, 'product_id' => $this->product->id, 'quantity' => '1', 'unit_price' => '16', 'payment_method' => $method, 'operation_date' => '2026-08-20', 'idempotency_key' => (string) Str::uuid(), 'notes' => null];
    }
}
