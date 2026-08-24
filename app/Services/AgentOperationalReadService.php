<?php

namespace App\Services;

use App\Agent\AgentPeriodResolver;
use App\Models\Ingredient;
use App\Models\Location;
use App\Models\PdvConnection;
use App\Models\Product;
use App\Models\Supplier;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AgentOperationalReadService
{
    public function __construct(
        private AgentPeriodResolver $periods,
        private SalesSummaryService $sales,
        private ProductSalesRankingService $ranking,
        private PaymentFeeReportService $payments,
        private StockPositionService $stock,
        private IngredientStockPositionService $ingredientStock,
        private IngredientSemanticResolver $ingredientResolver,
        private PdvHealthService $pdvHealth,
        private PdvSalesReconciliationService $pdvReconciliation,
        private ProductMatchService $productMatcher,
    ) {}

    /** @return array<string, mixed> */
    public function salesSummary(Location $location, array $input): array
    {
        $period = $this->periods->resolve($input);
        $summary = $this->sales->summarize(
            $location,
            $period['from']->toDateString(),
            $period['to']->toDateString(),
            isset($input['payment_method']) ? (string) $input['payment_method'] : null,
        );

        return [
            'location' => $this->location($location),
            'period' => $this->period($period),
            'sales_count' => $summary['sales_count'],
            'quantity' => $summary['quantity'],
            'revenue' => $summary['revenue'],
            'discounts' => $summary['discounts'],
            'average_ticket' => $summary['average_ticket'],
            'fees' => $summary['fees'],
            'net' => $summary['net'],
            'by_product' => collect($summary['by_product'])->map(fn (object $row): array => [
                'name' => $row->name,
                'unit' => $row->stock_unit,
                'quantity' => (string) $row->quantity,
                'revenue' => (string) $row->revenue,
            ])->values()->all(),
            'by_category' => collect($summary['by_category'])->map(fn (object $row): array => [
                'name' => $row->category,
                'quantity' => (string) $row->quantity,
                'revenue' => (string) $row->revenue,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function productRanking(Location $location, array $input): array
    {
        $period = $this->periods->resolve($input);
        $limit = $this->limit($input, 10, 20);
        $product = filled($input['product_name'] ?? null) ? $this->product((string) $input['product_name']) : null;
        $rows = $this->ranking->forPeriod($period['from']->toDateString(), $period['to']->toDateString(), $location, $product?->id)
            ->take($limit)
            ->values();

        return [
            'location' => $this->location($location),
            'period' => $this->period($period),
            'items' => $rows->map(fn (object $row, int $index): array => [
                'rank' => $index + 1,
                'product_id' => (int) $row->id,
                'name' => $row->name,
                'quantity' => (string) $row->quantity,
                'revenue' => (string) $row->revenue,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function paymentSummary(Location $location, array $input): array
    {
        $period = $this->periods->resolve($input);
        $summary = $this->payments->summarize($location, $period['from']->toDateString(), $period['to']->toDateString());
        $methods = collect($summary['by_method'])->map(fn (object $row): array => [
            'method' => $row->payment_method,
            'gross' => (string) $row->gross,
            'fees' => (string) $row->fees,
            'net' => (string) $row->net,
        ])->values();
        $requested = trim((string) ($input['payment_method'] ?? ''));
        if ($requested !== '') {
            $methods = $methods->filter(fn (array $row): bool => $this->paymentMatches($requested, $row['method']))->values();
            $summary['gross'] = $this->sum($methods->pluck('gross'));
            $summary['fees'] = $this->sum($methods->pluck('fees'));
            $summary['net'] = $this->sum($methods->pluck('net'));
        }

        return [
            'location' => $this->location($location),
            'period' => $this->period($period),
            'filter' => $requested !== '' ? $requested : null,
            'gross' => (string) $summary['gross'],
            'fees' => (string) $summary['fees'],
            'net' => (string) $summary['net'],
            'by_method' => $methods->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function productStock(Location $location, array $input): array
    {
        $product = filled($input['product_name'] ?? null) ? $this->product((string) $input['product_name']) : null;
        $zeroOnly = filter_var($input['zero_only'] ?? false, FILTER_VALIDATE_BOOL);
        $items = collect($this->stock->forLocation($location))
            ->when($product, fn (Collection $positions) => $positions->where('product.id', $product->id))
            ->when($zeroOnly, fn (Collection $positions) => $positions->filter(fn (array $position): bool => BigDecimal::of($position['balance'])->isZero()))
            ->take($this->limit($input, 30, 100))
            ->map(fn (array $position): array => [
                'product_id' => $position['product']->id,
                'name' => $position['product']->name,
                'unit' => $position['product']->stock_unit,
                'balance' => $position['balance'],
                'minimum' => $position['minimum'],
                'target' => $position['target'],
                'situation' => $position['situation']->value,
            ])->values()->all();

        return ['location' => $this->location($location), 'items' => $items];
    }

    /** @return array<string, mixed> */
    public function ingredientStock(Location $location, array $input): array
    {
        $filters = [];
        if (filled($input['ingredient_name'] ?? null)) {
            $resolution = $this->ingredientResolver->resolve((string) $input['ingredient_name']);
            if ($resolution['status'] !== 'resolved') {
                if ($resolution['status'] === 'target_missing') {
                    return ['location' => $this->location($location), 'items' => []];
                }

                throw new DomainException('Insumo não encontrado ou ambíguo no cadastro oficial.');
            }
            $filters['ingredient_id'] = $resolution['ingredient']->id;
        }
        $positions = $this->ingredientStock->forLocation($location, $filters)->take($this->limit($input, 30, 100));

        return [
            'location' => $this->location($location),
            'items' => $positions->map(fn (array $position): array => [
                'ingredient_id' => $position['ingredient']->id,
                'name' => $position['ingredient']->name,
                'unit' => $position['ingredient']->base_unit,
                'balance' => $position['balance'],
                'last_movement_at' => $position['last_movement']?->operation_date?->toDateString(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function pdvHealth(Location $location): array
    {
        $connections = $this->connections($location);
        $health = $this->pdvHealth->forConnections($connections);

        return [
            'location' => $this->location($location),
            'connections' => $connections->map(function (PdvConnection $connection) use ($health): array {
                $row = $health->get($connection->id, []);

                return [
                    'connection_id' => $connection->id,
                    'provider' => $connection->provider,
                    'enabled' => (bool) $connection->enabled,
                    'last_sync_at' => $row['last_sync']?->toIso8601String(),
                    'staged' => (int) ($row['staged'] ?? 0),
                    'ready' => (int) ($row['ready'] ?? 0),
                    'blocked' => (int) ($row['blocked'] ?? 0),
                    'imported' => (int) ($row['imported'] ?? 0),
                    'reversed' => (int) ($row['reversed'] ?? 0),
                    'sync_enabled' => (bool) ($row['sync_enabled'] ?? false),
                    'import_enabled' => (bool) ($row['import_enabled'] ?? false),
                ];
            })->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function pdvReconciliation(Location $location, array $input): array
    {
        $period = $this->periods->resolve($input);
        $connections = $this->connections($location);

        return [
            'location' => $this->location($location),
            'period' => $this->period($period),
            'connections' => $connections->map(function (PdvConnection $connection) use ($period): array {
                $result = $this->pdvReconciliation->period($connection, $period['from'], $period['to']);

                return [
                    'connection_id' => $connection->id,
                    'provider' => $connection->provider,
                    'summary' => $result['summary'],
                    'external_payments' => collect($result['external_payments'])->map(fn (array $row): array => $row)->values()->all(),
                    'official_payments' => collect($result['official_payments'])->map(fn (array $row): array => $row)->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function catalogPrices(array $input): array
    {
        $product = filled($input['product_name'] ?? null) ? $this->product((string) $input['product_name']) : null;
        $items = Product::query()->where('active', true)->with(['currentPrice', 'category'])
            ->when($product, fn ($query) => $query->whereKey($product->id))
            ->orderBy('sort_order')->orderBy('name')
            ->limit($this->limit($input, 20, 100))->get()
            ->map(fn (Product $item): array => [
                'product_id' => $item->id,
                'name' => $item->name,
                'category' => $item->category?->name,
                'price' => $item->currentPrice?->price,
                'price_effective_date' => $item->currentPrice?->effective_date?->toDateString(),
                'price_status' => $item->currentPrice === null ? 'not_configured' : 'current',
            ])->all();

        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    public function catalogProducts(array $input): array
    {
        $product = filled($input['product_name'] ?? null) ? $this->product((string) $input['product_name']) : null;
        $items = Product::query()->with(['category', 'currentPrice', 'recipe'])
            ->when($product, fn ($query) => $query->whereKey($product->id))
            ->when(array_key_exists('active', $input) && $input['active'] !== null, fn ($query) => $query->where('active', filter_var($input['active'], FILTER_VALIDATE_BOOL)))
            ->when(filter_var($input['without_price'] ?? false, FILTER_VALIDATE_BOOL), fn ($query) => $query->whereDoesntHave('currentPrice'))
            ->when(filter_var($input['without_recipe'] ?? false, FILTER_VALIDATE_BOOL), fn ($query) => $query->whereDoesntHave('recipe'))
            ->when(filled($input['category'] ?? null), fn ($query) => $query->whereHas('category', fn ($category) => $category->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower((string) $input['category']).'%'])))
            ->orderBy('sort_order')->orderBy('name')
            ->limit($this->limit($input, 30, 100))->get()
            ->map(fn (Product $item): array => [
                'product_id' => $item->id,
                'name' => $item->name,
                'category' => $item->category?->name,
                'stock_unit' => $item->stock_unit,
                'active' => (bool) $item->active,
                'selling_price' => $item->currentPrice?->price,
                'price_effective_date' => $item->currentPrice?->effective_date?->toDateString(),
                'has_recipe' => $item->recipe !== null,
            ])->all();

        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    public function catalogIngredients(array $input): array
    {
        $ingredientId = null;
        if (filled($input['ingredient_name'] ?? null)) {
            $resolved = $this->ingredientResolver->resolve((string) $input['ingredient_name']);
            if ($resolved['status'] !== 'resolved') {
                throw new DomainException('Insumo não encontrado ou ambíguo no cadastro oficial.');
            }
            $ingredientId = $resolved['ingredient']->id;
        }
        $items = Ingredient::query()->with(['category', 'currentPrice.supplier'])
            ->when($ingredientId, fn ($query) => $query->whereKey($ingredientId))
            ->when(array_key_exists('active', $input) && $input['active'] !== null, fn ($query) => $query->where('active', filter_var($input['active'], FILTER_VALIDATE_BOOL)))
            ->when(filter_var($input['without_price'] ?? false, FILTER_VALIDATE_BOOL), fn ($query) => $query->whereDoesntHave('currentPrice'))
            ->orderBy('name')->limit($this->limit($input, 30, 100))->get()
            ->map(fn (Ingredient $item): array => [
                'ingredient_id' => $item->id,
                'name' => $item->name,
                'brand' => $item->brand,
                'category' => $item->category?->name,
                'base_unit' => $item->base_unit,
                'active' => (bool) $item->active,
                'current_base_unit_cost' => $item->currentPrice?->base_unit_cost,
                'current_supplier' => $item->currentPrice?->supplier?->name,
                'price_effective_date' => $item->currentPrice?->effective_date?->toDateString(),
            ])->all();

        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    public function suppliers(array $input): array
    {
        $items = Supplier::query()
            ->when(filled($input['supplier_name'] ?? null), fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower((string) $input['supplier_name']).'%']))
            ->when(array_key_exists('active', $input) && $input['active'] !== null, fn ($query) => $query->where('active', filter_var($input['active'], FILTER_VALIDATE_BOOL)))
            ->orderBy('name')->limit($this->limit($input, 30, 100))->get()
            ->map(fn (Supplier $supplier): array => [
                'supplier_id' => $supplier->id,
                'name' => $supplier->name,
                'contact_name' => $supplier->contact_name,
                'phone' => $supplier->phone,
                'active' => (bool) $supplier->active,
            ])->all();

        return ['items' => $items];
    }

    private function product(string $name): Product
    {
        $resolved = $this->productMatcher->resolveExactItems([['product_name' => trim($name)]])[0];
        if (isset($resolved['product_id'])) {
            return Product::query()->where('active', true)->findOrFail($resolved['product_id']);
        }

        $suggestion = $this->productMatcher->matchItems([['product_name' => trim($name)]])[0]['_product_match'] ?? [];
        $candidates = collect($suggestion['candidates'] ?? [])->pluck('name')->filter()->take(5)->implode(', ');
        if ($candidates !== '') {
            throw new DomainException('Produto não identificado com segurança. Confirme um nome oficial: '.$candidates.'.');
        }

        throw new DomainException('Produto não encontrado no catálogo oficial.');
    }

    /** @return Collection<int, PdvConnection> */
    private function connections(Location $location): Collection
    {
        return PdvConnection::query()->whereBelongsTo($location)->where('provider', 'grandchef')->with('location')->orderBy('id')->get();
    }

    /** @param array{from: CarbonImmutable, to: CarbonImmutable, label: string} $period */
    private function period(array $period): array
    {
        return ['from' => $period['from']->toDateString(), 'to' => $period['to']->toDateString(), 'label' => $period['label']];
    }

    /** @return array{id: int, name: string} */
    private function location(Location $location): array
    {
        return ['id' => $location->id, 'name' => $location->name];
    }

    private function limit(array $input, int $default, int $maximum): int
    {
        $limit = filter_var($input['limit'] ?? $default, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > $maximum) {
            throw new DomainException("O limite deve estar entre 1 e {$maximum}.");
        }

        return $limit;
    }

    private function paymentMatches(string $requested, string $actual): bool
    {
        $needle = $this->normalize($requested);
        $value = $this->normalize($actual);

        return match ($needle) {
            'cartao', 'cartao credito', 'cartao debito' => str_contains($value, 'cartao') || str_contains($value, 'credito') || str_contains($value, 'debito'),
            'dinheiro' => str_contains($value, 'dinheiro') || str_contains($value, 'cash'),
            'pix' => str_contains($value, 'pix'),
            default => $value === $needle,
        };
    }

    private function normalize(string $value): string
    {
        return Str::squish(Str::lower(Str::ascii($value)));
    }

    /** @param Collection<int, string> $values */
    private function sum(Collection $values): string
    {
        return (string) $values->reduce(
            fn (BigDecimal $sum, string $value): BigDecimal => $sum->plus($value),
            BigDecimal::zero(),
        )->toScale(2, RoundingMode::HalfUp);
    }
}
