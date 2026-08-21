<?php

namespace Tests\Feature;

use App\Models\IngredientStockMovement;
use App\Models\Location;
use App\Models\PdvConnection;
use App\Models\PdvProductMapping;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSale;
use App\Models\StockMovement;
use App\Models\User;
use App\Pdv\GrandChefQueryContract;
use App\Services\GrandChefSalesReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\FakeGrandChefQueryContract;
use Tests\TestCase;

class GrandChefReportTest extends TestCase
{
    use RefreshDatabase;

    private PdvConnection $connection;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $location = Location::query()->create(['name' => 'Unidade Ibirá', 'type' => 'store', 'active' => true]);
        $this->admin = User::factory()->create();
        $this->connection = PdvConnection::query()->firstOrFail();
        $this->connection->update([
            'location_id' => $location->id,
            'name' => 'GrandChef Ibirá',
            'enabled' => true,
            'configuration' => ['endpoint' => 'https://ibira.invalid/graphql'],
            'encrypted_credentials' => ['bearer_token' => 'report-token', 'device_token' => 'report-device'],
        ]);
        $this->app->singleton(GrandChefQueryContract::class, FakeGrandChefQueryContract::class);
    }

    public function test_paginated_report_sums_orders_items_discounts_payments_ticket_and_coxinhas_without_operational_writes(): void
    {
        $this->confirmedMappings();
        Http::fakeSequence()
            ->push(['data' => ['fixture' => ['orders' => [$this->knownOrder()], 'next_cursor' => ['page' => 2], 'total' => 2]]])
            ->push(['data' => ['fixture' => ['orders' => [$this->splitPaymentOrder()], 'next_cursor' => null, 'total' => 2]]]);
        $before = $this->operationalCounts();

        $report = app(GrandChefSalesReportService::class)->report(
            $this->connection,
            CarbonImmutable::parse('2026-08-15', 'America/Sao_Paulo'),
            CarbonImmutable::parse('2026-08-15', 'America/Sao_Paulo'),
        );

        $this->assertSame(2, $report['summary']['orders']);
        $this->assertSame('5.000000', $report['summary']['items_quantity']);
        $this->assertSame('94.00', $report['summary']['gross_amount']);
        $this->assertSame('6.00', $report['summary']['discount_amount']);
        $this->assertSame('88.00', $report['summary']['total_amount']);
        $this->assertSame('94.00', $report['summary']['paid_amount']);
        $this->assertSame('44.00', $report['summary']['average_ticket']);
        $this->assertSame('4.000000', $report['summary']['confirmed_coxinha_quantity']);
        $this->assertTrue($report['summary']['coxinha_count_complete']);
        $this->assertSame(2, $report['pagination']['pages']);
        $this->assertSame(2, $report['pagination']['reported_total']);
        $this->assertTrue($report['pagination']['complete']);
        $this->assertSame('66.00', collect($report['items'])->firstWhere('external_product_id', 'PROD-COSTELA')['total']);
        $this->assertSame('44.00', collect($report['payments'])->firstWhere('method_code', 'DEBIT')['amount']);
        $this->assertSame('20.00', collect($report['payments'])->firstWhere('method_code', 'PIX')['amount']);
        $this->assertSame('30.00', collect($report['payments'])->firstWhere('method_code', 'CASH')['amount']);
        $this->assertSame($before, $this->operationalCounts());
        Http::assertSentCount(2);
    }

    public function test_unmapped_products_never_create_an_invented_coxinha_total(): void
    {
        Http::fake(['*' => Http::response(['data' => ['fixture' => ['orders' => [$this->knownOrder()], 'next_cursor' => null, 'total' => 1]]])]);

        $report = app(GrandChefSalesReportService::class)->report($this->connection, CarbonImmutable::parse('2026-08-15'), CarbonImmutable::parse('2026-08-15'));

        $this->assertSame('0.000000', $report['summary']['confirmed_coxinha_quantity']);
        $this->assertFalse($report['summary']['coxinha_count_complete']);
    }

    public function test_period_filter_uses_sao_paulo_operation_datetime(): void
    {
        $outside = $this->knownOrder();
        $outside['id'] = 'OUTSIDE';
        $outside['closed_at'] = '2026-08-16 00:01:00';
        Http::fake(['*' => Http::response(['data' => ['fixture' => ['orders' => [$this->knownOrder(), $outside], 'next_cursor' => null, 'total' => 2]]])]);

        $report = app(GrandChefSalesReportService::class)->report($this->connection, CarbonImmutable::parse('2026-08-15', 'America/Sao_Paulo'), CarbonImmutable::parse('2026-08-15', 'America/Sao_Paulo'));

        $this->assertSame(1, $report['summary']['orders']);
        $this->assertFalse($report['pagination']['complete']);
        Http::assertSent(function (Request $request): bool {
            $variables = $request->data()['variables'];

            return str_contains($variables['from'], '2026-08-15') && str_contains($variables['to'], '2026-08-15');
        });
    }

    public function test_repeated_pagination_cursor_is_stopped_safely(): void
    {
        Http::fakeSequence()
            ->push(['data' => ['fixture' => ['orders' => [$this->knownOrder()], 'next_cursor' => ['page' => 2], 'total' => 3]]])
            ->push(['data' => ['fixture' => ['orders' => [$this->splitPaymentOrder()], 'next_cursor' => ['page' => 2], 'total' => 3]]]);

        $this->expectException(RuntimeException::class);
        app(GrandChefSalesReportService::class)->report($this->connection, CarbonImmutable::parse('2026-08-15'), CarbonImmutable::parse('2026-08-15'));
    }

    public function test_order_detail_displays_items_multiple_payments_discount_change_and_external_ids(): void
    {
        Http::fake(['*' => Http::response(['data' => ['fixture' => ['order' => $this->splitPaymentOrder()]]])]);

        $this->actingAs($this->admin)
            ->get(route('pdv.reports.orders.show', [$this->connection, 'ORDER-SPLIT']))
            ->assertOk()
            ->assertSee('Costela com Queijo')
            ->assertSee('Pix')
            ->assertSee('Dinheiro')
            ->assertSee('R$ 6,00')
            ->assertSee('ORDER-SPLIT');
    }

    public function test_report_screen_is_read_only_and_renders_real_summary_from_fake_transport(): void
    {
        Http::fake(['*' => Http::response(['data' => ['fixture' => ['orders' => [$this->knownOrder()], 'next_cursor' => null, 'total' => 1]]])]);
        $before = $this->operationalCounts();

        $this->actingAs($this->admin)
            ->get(route('pdv.reports.sales', [$this->connection, 'from' => '2026-08-15', 'to' => '2026-08-15']))
            ->assertOk()
            ->assertSee('Vendas GrandChef')
            ->assertSee('111882807')
            ->assertSee('R$ 44,00')
            ->assertSee('somente leitura');

        $this->assertSame($before, $this->operationalCounts());
    }

    private function confirmedMappings(): void
    {
        $coxinhas = ProductCategory::query()->create(['name' => 'Coxinhas', 'active' => true]);
        $bebidas = ProductCategory::query()->create(['name' => 'Bebidas', 'active' => true]);
        foreach ([
            ['PROD-COSTELA', 'Costela com Queijo', $coxinhas],
            ['PROD-PERNIL', 'Pernil com Bacon', $coxinhas],
            ['PROD-COCA', 'Coca-Cola Zero', $bebidas],
        ] as [$externalId, $name, $category]) {
            $product = Product::query()->create(['name' => $name, 'product_category_id' => $category->id, 'stock_unit' => 'un', 'active' => true]);
            PdvProductMapping::query()->create(['pdv_connection_id' => $this->connection->id, 'external_product_id' => $externalId, 'external_name' => $name, 'product_id' => $product->id, 'status' => 'confirmed', 'match_source' => 'admin']);
        }
    }

    private function knownOrder(): array
    {
        return [
            'id' => '111882807', 'code' => '111882807', 'status' => 'closed', 'closed_at' => '2026-08-15 20:15:00', 'gross_amount' => '44.00', 'discount_amount' => '0.00', 'net_amount' => '44.00', 'paid_amount' => '44.00', 'change_amount' => '0.00',
            'items' => [
                ['id' => 'ITEM-COCA', 'product_id' => 'PROD-COCA', 'code' => 'COCA-ZERO', 'name' => 'Coca-Cola Zero lata 350 ml', 'quantity' => '1', 'unit_price' => '6.00', 'total' => '6.00'],
                ['id' => 'ITEM-COSTELA', 'product_id' => 'PROD-COSTELA', 'code' => 'COX-COSTELA', 'name' => 'Coxinha de Costela com Queijo', 'quantity' => '1', 'unit_price' => '22.00', 'total' => '22.00'],
                ['id' => 'ITEM-PERNIL', 'product_id' => 'PROD-PERNIL', 'code' => 'COX-PERNIL', 'name' => 'Coxinha de Pernil com Bacon', 'quantity' => '1', 'unit_price' => '16.00', 'total' => '16.00'],
            ],
            'payments' => [['id' => 'PAY-DEBIT', 'method_code' => 'DEBIT', 'method_name' => 'Débito', 'type' => 'card', 'amount' => '44.00', 'status' => 'paid']],
        ];
    }

    private function splitPaymentOrder(): array
    {
        return [
            'id' => 'ORDER-SPLIT', 'code' => '1002', 'status' => 'closed', 'closed_at' => '2026-08-15 21:30:00', 'gross_amount' => '50.00', 'discount_amount' => '6.00', 'net_amount' => '44.00', 'paid_amount' => '50.00', 'change_amount' => '6.00',
            'items' => [['id' => 'ITEM-COSTELA-2', 'product_id' => 'PROD-COSTELA', 'code' => 'COX-COSTELA', 'name' => 'Coxinha de Costela com Queijo', 'quantity' => '2', 'unit_price' => '22.00', 'discount' => '0.00', 'total' => '44.00']],
            'payments' => [
                ['id' => 'PAY-PIX', 'method_code' => 'PIX', 'method_name' => 'Pix', 'type' => 'instant', 'amount' => '20.00', 'status' => 'paid'],
                ['id' => 'PAY-CASH', 'method_code' => 'CASH', 'method_name' => 'Dinheiro', 'type' => 'cash', 'amount' => '30.00', 'change_amount' => '6.00', 'status' => 'paid'],
            ],
        ];
    }

    private function operationalCounts(): array
    {
        return [
            'product_sales' => ProductSale::query()->count(),
            'stock_movements' => StockMovement::query()->count(),
            'ingredient_stock_movements' => IngredientStockMovement::query()->count(),
        ];
    }
}
