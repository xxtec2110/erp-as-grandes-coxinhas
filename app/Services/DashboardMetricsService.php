<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\Payable;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductSale;
use App\Models\PurchaseDocument;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardMetricsService
{
    public function __construct(
        private AuthorizationService $authorization,
        private SalesSummaryService $sales,
        private ProductMarginService $margins,
        private IngredientPriceAnalyticsService $ingredientPrices,
        private IngredientShortageService $ingredientShortages,
    ) {}

    /** @param Collection<int, array<string, mixed>> $widgets
     * @return array<string, mixed>
     */
    public function load(Collection $widgets, User $user, Location $location, string $start, string $end): array
    {
        $keys = $widgets->pluck('key');
        $data = [];

        $financialSalesKeys = ['dashboard.revenue', 'dashboard.gross_profit', 'dashboard.gross_margin'];
        if ($keys->intersect($financialSalesKeys)->isNotEmpty()) {
            $summary = $this->sales->summarize($location, $start, $end);
            $data['revenue'] = ['value' => $summary['revenue'], 'format' => 'money', 'empty' => BigDecimal::of($summary['revenue'])->isZero(), 'caption' => 'Faturamento bruto confirmado'];
            $data['gross_profit'] = ['value' => $summary['gross_profit'], 'format' => 'money', 'empty' => $summary['gross_profit'] === null, 'caption' => $summary['gross_profit'] === null ? "{$summary['missing_cost_count']} venda(s) sem custo preservado" : 'Receita menos custo dos produtos vendidos'];
            $data['gross_margin'] = ['value' => $summary['gross_margin_percentage'], 'format' => 'percent', 'empty' => $summary['gross_margin_percentage'] === null, 'caption' => $summary['gross_margin_percentage'] === null ? 'Margem indisponível por custo incompleto' : 'Margem sobre as vendas confirmadas'];
        }

        $needsQuantity = $keys->intersect(['dashboard.sales_quantity', 'dashboard.daily_goal', 'dashboard.top_flavors'])->isNotEmpty();
        $salesByProduct = collect();
        $salesQuantity = '0';
        if ($needsQuantity) {
            $query = ProductSale::query()->where('location_id', $location->id)->whereBetween('operation_date', [$start, $end]);
            $salesQuantity = (string) (clone $query)->sum('quantity');
            if ($keys->contains('dashboard.top_flavors')) {
                $salesByProduct = (clone $query)->join('products', 'products.id', '=', 'product_sales.product_id')
                    ->selectRaw('products.id, products.name, products.stock_unit, SUM(product_sales.quantity) AS quantity')
                    ->groupBy('products.id', 'products.name', 'products.stock_unit')->orderByDesc('quantity')->limit(8)->get();
            }
        }
        if ($keys->contains('dashboard.sales_quantity')) {
            $data['sales_quantity'] = ['value' => $salesQuantity, 'format' => 'quantity', 'empty' => BigDecimal::of($salesQuantity)->isZero(), 'caption' => 'Quantidade oficial vendida'];
        }
        if ($keys->contains('dashboard.daily_goal')) {
            $target = $location->daily_sales_target;
            $progress = $target === null || BigDecimal::of($target)->isZero() ? null : (string) BigDecimal::of($salesQuantity)->multipliedBy(100)->dividedBy($target, 2, RoundingMode::HalfUp);
            $data['daily_goal'] = ['target' => $target, 'sold' => $salesQuantity, 'progress' => $progress];
        }
        if ($keys->contains('dashboard.top_flavors')) {
            $data['top_flavors'] = $salesByProduct;
        }

        if ($keys->contains('dashboard.operational_summary')) {
            $data['operational_summary'] = $this->operationalSummary($location, $start, $end);
        }

        $positions = collect();
        if ($keys->contains('dashboard.stock_balance') || ($keys->contains('dashboard.operational_alerts') && $this->authorization->allows($user, 'stock.view', $location))) {
            $positions = $this->stockPositions($location);
            if ($keys->contains('dashboard.stock_balance')) {
                $data['stock_balance'] = $positions;
            }
        }

        if ($keys->contains('dashboard.flavor_performance')) {
            $data['flavor_performance'] = $this->margins->report();
        }
        if ($keys->contains('dashboard.ingredient_price_variation')) {
            $data['ingredient_price_variation'] = $this->ingredientPrices->variationReport(Carbon::parse($start), Carbon::parse($end))->take(10);
        }

        $payables = collect();
        if ($keys->intersect(['dashboard.accounts_payable', 'dashboard.upcoming_payables'])->isNotEmpty()
            || ($keys->contains('dashboard.operational_alerts') && $this->authorization->allows($user, 'finance.payables.view', $location))) {
            $payables = Payable::query()->with(['supplier'])
                ->withSum(['payments as paid_amount' => fn ($query) => $query->where('status', 'completed')], 'amount')
                ->where('location_id', $location->id)->whereNotIn('status', ['paid', 'cancelled'])
                ->orderBy('due_date')->get()->map(function (Payable $payable): Payable {
                    $payable->setAttribute('outstanding_amount', (string) BigDecimal::of($payable->expected_amount)->minus($payable->paid_amount ?? 0)->toScale(2, RoundingMode::HalfUp));

                    return $payable;
                })->filter(fn (Payable $payable) => BigDecimal::of($payable->outstanding_amount)->isPositive())->values();
        }
        if ($keys->contains('dashboard.accounts_payable')) {
            $data['accounts_payable'] = [
                'count' => $payables->count(),
                'open' => $this->sum($payables, 'outstanding_amount'),
                'overdue' => $this->sum($payables->filter(fn (Payable $item) => $item->due_date->isBefore(now()->startOfDay())), 'outstanding_amount'),
                'upcoming' => $this->sum($payables->filter(fn (Payable $item) => $item->due_date->betweenIncluded(now()->startOfDay(), now()->addDays(7)->endOfDay())), 'outstanding_amount'),
            ];
        }
        if ($keys->contains('dashboard.upcoming_payables')) {
            $data['upcoming_payables'] = $payables->take(8);
        }

        if ($keys->contains('dashboard.recent_purchases')) {
            $data['recent_purchases'] = PurchaseDocument::query()->with('supplier')->where('location_id', $location->id)
                ->where('source_type', '!=', 'quote')->where('document_status', 'confirmed')
                ->latest('issue_date')->latest('id')->limit(8)->get();
        }
        if ($keys->contains('dashboard.cash_flow')) {
            $entries = (string) ProductSale::query()->where('location_id', $location->id)->whereBetween('operation_date', [$start, $end])->sum('net_amount');
            $outflows = (string) Payment::query()->where('status', 'completed')->whereHas('payable', fn ($query) => $query->where('location_id', $location->id))->whereBetween('paid_at', [Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay()])->sum('amount');
            $data['cash_flow'] = ['entries' => $entries, 'outflows' => $outflows, 'balance' => (string) BigDecimal::of($entries)->minus($outflows)->toScale(2, RoundingMode::HalfUp), 'caption' => 'Vendas líquidas e pagamentos identificados no ERP; não é projeção bancária.'];
        }

        if ($keys->contains('dashboard.operational_alerts')) {
            $data['operational_alerts'] = $this->alerts($user, $location, $positions, $payables, $salesQuantity, $start, $end);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function operationalSummary(Location $location, string $start, string $end): array
    {
        $rows = StockMovement::query()->where('location_id', $location->id)->whereBetween('operation_date', [$start, $end])
            ->selectRaw('type, SUM(ABS(quantity_delta)) AS quantity')->groupBy('type')->pluck('quantity', 'type');
        $sumTypes = fn (array $types): string => (string) collect($types)->reduce(fn (BigDecimal $total, string $type) => $total->plus($rows->get($type, '0')), BigDecimal::zero())->toScale(6, RoundingMode::HalfUp);

        return [
            'production' => $sumTypes([StockMovementType::Production->value]),
            'entries' => $sumTypes([StockMovementType::Entry->value, StockMovementType::OpeningBalance->value, StockMovementType::TransferIn->value]),
            'outbound' => $sumTypes([StockMovementType::Sale->value, StockMovementType::Outbound->value, StockMovementType::TransferOut->value]),
            'losses' => $sumTypes([StockMovementType::Loss->value]),
            'planned_orders' => ProductionOrder::query()->where('location_id', $location->id)->where('status', 'planned')->count(),
            'completed_orders' => ProductionOrder::query()->where('location_id', $location->id)->where('status', 'completed')->whereBetween('production_date', [$start, $end])->count(),
            'in_transit' => StockTransfer::query()->where('status', 'in_transit')->where(fn ($query) => $query->where('source_location_id', $location->id)->orWhere('destination_location_id', $location->id))->count(),
        ];
    }

    /** @return Collection<int, object> */
    private function stockPositions(Location $location): Collection
    {
        return Product::query()->where('products.active', true)
            ->leftJoin('stock_movements', fn ($join) => $join->on('stock_movements.product_id', '=', 'products.id')->where('stock_movements.location_id', '=', $location->id))
            ->leftJoin('product_stock_policies', fn ($join) => $join->on('product_stock_policies.product_id', '=', 'products.id')->where('product_stock_policies.location_id', '=', $location->id)->where('product_stock_policies.active', '=', true))
            ->selectRaw('products.id, products.name, products.stock_unit, COALESCE(SUM(stock_movements.quantity_delta), 0) AS balance, MAX(product_stock_policies.minimum_quantity) AS minimum, MAX(product_stock_policies.target_quantity) AS target')
            ->groupBy('products.id', 'products.name', 'products.stock_unit')->orderBy('products.name')->get()
            ->map(function ($row) {
                $balance = BigDecimal::of($row->balance);
                $row->situation = match (true) {
                    $row->target === null => 'not_configured',
                    $row->minimum !== null && $balance->isLessThan($row->minimum) => 'critical',
                    $balance->isLessThan($row->target) => 'attention',
                    default => 'ok',
                };

                return $row;
            });
    }

    /** @return Collection<int, array<string, string>> */
    private function alerts(User $user, Location $location, Collection $positions, Collection $payables, string $salesQuantity, string $start, string $end): Collection
    {
        $alerts = collect();
        if ($this->authorization->allows($user, 'stock.view', $location)) {
            foreach ($positions->whereIn('situation', ['critical', 'attention'])->take(5) as $position) {
                $alerts->push(['level' => $position->situation === 'critical' ? 'critical' : 'warning', 'title' => "Estoque {$position->situation}", 'message' => $position->name.' está com saldo '.$position->balance.' '.$position->stock_unit.'.']);
            }
        }
        if ($this->authorization->allows($user, 'finance.payables.view', $location)) {
            foreach ($payables->filter(fn (Payable $item) => $item->due_date->isBefore(now()->startOfDay()))->take(3) as $payable) {
                $alerts->push(['level' => 'critical', 'title' => 'Conta vencida', 'message' => $payable->description.' venceu em '.$payable->due_date->format('d/m/Y').'.']);
            }
        }
        if ($this->authorization->allows($user, 'ingredient_stock.view', $location)) {
            foreach (collect($this->ingredientShortages->forLocation($location))->take(3) as $shortage) {
                $alerts->push(['level' => 'warning', 'title' => 'Insumo insuficiente', 'message' => $shortage['ingredient']->name.' não cobre as ordens planejadas.']);
            }
        }
        if ($this->authorization->allows($user, 'production.orders.view', $location)) {
            $planned = ProductionOrder::query()->where('location_id', $location->id)->where('status', 'planned')->count();
            if ($planned > 0) {
                $alerts->push(['level' => 'warning', 'title' => 'Produção pendente', 'message' => "{$planned} ordem(ns) aguardando conclusão."]);
            }
        }
        if ($this->authorization->allows($user, 'sales.view', $location) && $start === now()->toDateString() && $end === $start && $location->daily_sales_target !== null && BigDecimal::of($salesQuantity)->isLessThan($location->daily_sales_target)) {
            $alerts->push(['level' => 'info', 'title' => 'Meta em andamento', 'message' => 'A meta diária ainda não foi atingida.']);
        }

        return $alerts->values();
    }

    private function sum(Collection $rows, string $field): string
    {
        return (string) $rows->reduce(fn (BigDecimal $total, $row) => $total->plus($row->{$field}), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp);
    }
}
