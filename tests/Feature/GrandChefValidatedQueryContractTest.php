<?php

namespace Tests\Feature;

use App\Models\IngredientStockMovement;
use App\Models\Location;
use App\Models\PdvConnection;
use App\Models\ProductSale;
use App\Models\StockMovement;
use App\Pdv\GrandChefQueryContract;
use App\Pdv\GrandChefRequestException;
use App\Pdv\GrandChefValidatedQueryContract;
use App\Services\GrandChefSalesReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GrandChefValidatedQueryContractTest extends TestCase
{
    use RefreshDatabase;

    private PdvConnection $connection;

    private GrandChefValidatedQueryContract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $location = Location::query()->create(['name' => 'Unidade Ibirá', 'type' => 'store', 'active' => true]);
        $this->connection = PdvConnection::query()->firstOrFail();
        $this->connection->update([
            'location_id' => $location->id,
            'name' => 'GrandChef Ibirá',
            'enabled' => true,
            'configuration' => ['endpoint' => 'https://ibira.invalid/graphql'],
            'encrypted_credentials' => ['bearer_token' => 'real-contract-test-token', 'device_token' => 'real-contract-test-device'],
        ]);
        $this->contract = app(GrandChefValidatedQueryContract::class);
    }

    public function test_validated_contract_is_registered_and_builds_real_date_filter_and_page_arguments(): void
    {
        $this->assertInstanceOf(GrandChefValidatedQueryContract::class, app(GrandChefQueryContract::class));

        $request = $this->contract->salesRequest(
            CarbonImmutable::parse('2026-08-15', 'America/Sao_Paulo'),
            CarbonImmutable::parse('2026-08-16', 'America/Sao_Paulo'),
            ['page' => 3],
        );

        $this->assertSame('GrandChefSales', $request->operationName);
        $this->assertSame('concluido', $request->variables['filter']['estado']['eq']);
        $this->assertSame('2026-08-15T00:00:00-03:00', $request->variables['filter']['data_conclusao']['from']);
        $this->assertSame('2026-08-16T23:59:59-03:00', $request->variables['filter']['data_conclusao']['to']);
        $this->assertSame(10, $request->variables['limit']);
        $this->assertSame(3, $request->variables['page']);
        $this->assertStringContainsString('pedidos(filter: $filter, limit: $limit, page: $page)', $request->query);
        $this->assertStringContainsString('data_conclusao', $request->query);

        $detail = $this->contract->saleRequest('111882807');
        $this->assertSame(['id' => '111882807'], $detail->variables);
        $this->assertStringContainsString('pedido(id: $id)', $detail->query);
        $this->assertStringContainsString('pagamentos', $detail->query);
        $this->assertStringContainsString('forma {', $detail->query);
    }

    public function test_real_pagination_and_detail_are_normalized_with_items_products_split_payments_and_nullables(): void
    {
        $first = $this->contract->normalizeSales($this->connection, ['pedidos' => $this->page(1, 2, true)]);
        $this->assertSame(['page' => 2], $first->nextCursor);
        $this->assertSame(2, $first->reportedTotal);
        $this->assertTrue($first->metadata['requires_detail_fetch']);
        $this->assertSame('111882807', $first->items[0]->externalSaleId);
        $this->assertSame([], $first->items[0]->items);

        $second = $this->contract->normalizeSales($this->connection, ['pedidos' => $this->page(2, 2, false)]);
        $this->assertNull($second->nextCursor);

        $sale = $this->contract->normalizeSale($this->connection, ['pedido' => $this->orderDetail()]);
        $this->assertNotNull($sale);
        $this->assertSame('44', $sale->grossAmount);
        $this->assertSame('4', $sale->discountAmount);
        $this->assertSame('40', $sale->netAmount);
        $this->assertSame('44', $sale->paidAmount);
        $this->assertSame('4', $sale->changeAmount);
        $this->assertCount(4, $sale->items);
        $this->assertSame('2408173', $sale->items[0]->externalProductId);
        $this->assertSame('66', $sale->items[0]->sku);
        $this->assertSame('Coca-Cola Zero lata 350 ml', $sale->items[0]->name);
        $this->assertSame('6', $sale->items[0]->subtotal);
        $this->assertFalse($sale->items[0]->cancelled);
        $this->assertTrue($sale->items[3]->cancelled);
        $this->assertCount(2, $sale->payments);
        $this->assertSame('99902', $sale->payments[0]->methodCode);
        $this->assertSame('Débito', $sale->payments[0]->methodName);
        $this->assertSame('debito', $sale->payments[0]->type);
        $this->assertSame('20', $sale->payments[0]->amount);
        $this->assertNull($sale->payments[0]->brand);
        $this->assertSame('0', $sale->payments[0]->metadata['fees']);
        $this->assertSame('0', $sale->payments[0]->fees);
        $this->assertSame(1, $sale->payments[0]->installmentNumber);
    }

    public function test_provider_fetches_real_list_and_each_required_detail_without_operational_writes(): void
    {
        Http::fakeSequence()
            ->push(['data' => ['pedidos' => $this->page(1, 1, false)]])
            ->push(['data' => ['pedido' => $this->orderDetail()]]);
        $before = $this->operationalCounts();

        $report = app(GrandChefSalesReportService::class)->report(
            $this->connection,
            CarbonImmutable::parse('2026-08-15', 'America/Sao_Paulo'),
            CarbonImmutable::parse('2026-08-15', 'America/Sao_Paulo'),
        );

        $this->assertSame(1, $report['summary']['orders']);
        $this->assertSame('3.000000', $report['summary']['items_quantity']);
        $this->assertSame('40.00', $report['summary']['total_amount']);
        $this->assertSame('44.00', $report['summary']['paid_amount']);
        $this->assertSame('40.00', $report['summary']['average_ticket']);
        $this->assertSame($before, $this->operationalCounts());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->data()['operationName'] === 'GrandChefSales');
        Http::assertSent(fn (Request $request): bool => $request->data()['operationName'] === 'GrandChefSale');
    }

    public function test_incomplete_real_payload_is_rejected_instead_of_inventing_financial_values(): void
    {
        $detail = $this->orderDetail();
        unset($detail['total']);

        $this->expectException(GrandChefRequestException::class);
        $this->expectExceptionMessage('não informou total válido');
        $this->contract->normalizeSale($this->connection, ['pedido' => $detail]);
    }

    private function page(int $currentPage, int $lastPage, bool $hasMore): array
    {
        return [
            'data' => [$this->orderHeader()],
            'total' => $lastPage,
            'per_page' => 10,
            'current_page' => $currentPage,
            'from' => (($currentPage - 1) * 10) + 1,
            'to' => $currentPage * 10,
            'last_page' => $lastPage,
            'has_more_pages' => $hasMore,
        ];
    }

    private function orderHeader(): array
    {
        return [
            'id' => '111882807',
            'codigo' => '111882807',
            'estado' => 'concluido',
            'subtotal' => 44,
            'descontos' => 4,
            'total' => 40,
            'pago' => 44,
            'troco' => 4,
            'quantidade' => 3,
            'data_criacao' => '2026-08-15T20:00:00-03:00',
            'data_conclusao' => '2026-08-15T20:15:00-03:00',
        ];
    }

    private function orderDetail(): array
    {
        return $this->orderHeader() + [
            'itens' => [
                ['id' => 'ITEM-1', 'produto_id' => '2408173', 'descricao' => 'Coca-Cola Zero lata 350 ml', 'preco' => 6, 'quantidade' => 1, 'subtotal' => 6, 'total' => 6, 'estado' => 'concluido', 'cancelado' => false, 'produto' => ['id' => '2408173', 'codigo' => '66', 'descricao' => 'Coca-Cola Zero lata 350 ml']],
                ['id' => 'ITEM-2', 'produto_id' => '2399746', 'descricao' => 'Coxinha de Costela com Queijo', 'preco' => 22, 'quantidade' => 1, 'subtotal' => 22, 'total' => 22, 'estado' => 'concluido', 'cancelado' => false, 'produto' => ['id' => '2399746', 'codigo' => '36', 'descricao' => 'Coxinha de Costela com Queijo']],
                ['id' => 'ITEM-3', 'produto_id' => '2399734', 'descricao' => 'Coxinha de Pernil com Bacon', 'preco' => 16, 'quantidade' => 1, 'subtotal' => 16, 'total' => 12, 'estado' => 'concluido', 'cancelado' => false, 'produto' => ['id' => '2399734', 'codigo' => '34', 'descricao' => 'Coxinha de Pernil com Bacon']],
                ['id' => 'ITEM-CANCELLED', 'produto_id' => null, 'descricao' => 'Item cancelado', 'preco' => 10, 'quantidade' => 1, 'subtotal' => 10, 'total' => 0, 'estado' => 'cancelado', 'cancelado' => true, 'produto' => null],
            ],
            'pagamentos' => [
                ['id' => '113063923', 'forma_id' => '99902', 'total' => 20, 'taxas' => 0, 'valor' => 20, 'numero_parcela' => 1, 'parcelas' => 1, 'estado' => 'pago', 'tipo' => 'receita', 'data_pagamento' => '2026-08-15T20:15:00-03:00', 'data_lancamento' => null, 'forma' => ['id' => '99902', 'descricao' => 'Débito', 'tipo' => 'debito']],
                ['id' => '113063924', 'forma_id' => '99903', 'total' => 24, 'taxas' => null, 'valor' => 24, 'numero_parcela' => null, 'parcelas' => null, 'estado' => 'pago', 'tipo' => 'receita', 'data_pagamento' => null, 'data_lancamento' => null, 'forma' => ['id' => '99903', 'descricao' => 'Dinheiro', 'tipo' => 'dinheiro']],
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
