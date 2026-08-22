<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\PdvConnection;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class PdvGoLiveService
{
    public function __construct(
        private PdvMappingCatalogService $catalog,
        private PdvOrderPreviewService $previews,
        private PdvOrderImportPlanService $plans,
        private StockBalanceService $balances,
    ) {}

    /** @return array<string,mixed> */
    public function build(PdvConnection $connection, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $catalog = $this->catalog->forPeriod($connection, $from, $to, 'all');
        $preview = $this->previews->period($connection, $from, $to);
        $dryRuns = collect($preview['orders'])->map(function (array $row): array {
            $plan = $this->plans->plan($row['order']);

            return [
                'order' => $row['order'],
                'ready' => $plan['ready'],
                'blockers' => $plan['blockers'],
                'warnings' => $plan['warnings'],
                'operational_classification' => $plan['operational_classification'],
                'total' => $row['order']->total,
                'external_items' => $row['order']->items->where('present_in_latest', true)->where('cancelled', false)->count(),
                'external_payments' => $row['order']->payments->where('present_in_latest', true)->count(),
                'items' => count($plan['items']),
                'payments' => count($plan['payments']),
                'movements' => count($plan['movements']),
                'fee' => $plan['totals']['payment_fee'],
                'net' => $plan['totals']['payment_net'],
            ];
        })->values();

        $summary = $catalog['summary'];
        $productsPending = $summary['products_distinct'] - $summary['products_mapped'];
        $paymentsPending = $summary['payments_distinct'] - $summary['payments_mapped'];
        $stockDeficits = collect($catalog['stock_preview'])
            ->filter(fn (array $row): bool => BigDecimal::of($row['deficit'])->isPositive());
        $stockInventory = $this->stockInventory($connection, $catalog['products']);
        $openingStockPending = $stockInventory->where('opening_stock_recorded', false)->count();
        $operationalStartSet = $connection->operational_start_at !== null;
        $categoryBlocked = collect($catalog['missing_products'])->where('category_gate', true)->count();
        $allPlansReady = $dryRuns->isNotEmpty() && $dryRuns->every(fn (array $plan): bool => $plan['ready']);

        $gateReasons = collect();
        $this->reason($gateReasons, $summary['products_without_candidate'] > 0, 'products_missing', $summary['products_without_candidate'].' produto(s) externo(s) ainda não possuem Product oficial.');
        $this->reason($gateReasons, $productsPending > 0, 'product_mappings_pending', $productsPending.' mapping(s) de produto aguardam confirmação humana.');
        $this->reason($gateReasons, $paymentsPending > 0, 'payment_mappings_pending', $paymentsPending.' forma(s) de pagamento aguardam mapping.');
        $this->reason($gateReasons, $summary['payments_unsupported'] > 0, 'payments_unsupported', $summary['payments_unsupported'].' forma(s) de pagamento não são suportadas.');
        $this->reason($gateReasons, $summary['payments_configuration_missing'] > 0, 'payment_configuration_missing', $summary['payments_configuration_missing'].' forma(s) exigem configuração financeira existente.');
        $this->reason($gateReasons, $summary['payments_rate_missing'] > 0, 'payment_rate_missing', $summary['payments_rate_missing'].' forma(s) não possuem taxa vigente para todo o período.');
        $this->reason($gateReasons, ! $operationalStartSet, 'operational_start_not_set', 'O marco oficial de início ainda não foi definido.');
        $this->reason($gateReasons, $openingStockPending > 0, 'opening_stock_pending', $openingStockPending.' produto(s) mapeado(s) ainda não possuem estoque inicial oficial nesta unidade.');
        $this->reason($gateReasons, $summary['products_mapped'] === 0, 'stock_not_identifiable', 'O estoque só pode ser conferido depois dos mappings de produto confirmados.');
        $this->reason($gateReasons, $stockDeficits->isNotEmpty(), 'stock_insufficient', $stockDeficits->count().' produto(s) mapeado(s) estão sem saldo suficiente.');
        $this->reason($gateReasons, ! $allPlansReady, 'orders_not_ready', $dryRuns->where('ready', false)->count().' pedido(s) continuam bloqueados no dry-run oficial.');

        $canEnableImport = $gateReasons->isEmpty();
        $importEnabled = (bool) config('pdv.import_enabled', false);
        $highConfidence = collect($catalog['products'])->filter(fn (array $row): bool => $row['mapping_status'] !== 'confirmed'
            && in_array($row['suggestion']['type'], [PdvExternalProductSuggestionService::TYPE_EXACT, PdvExternalProductSuggestionService::TYPE_ALIAS], true))->values();

        return [
            'catalog' => $catalog,
            'preview' => $preview,
            'dry_runs' => $dryRuns,
            'dry_run_summary' => [
                'orders' => $dryRuns->count(),
                'ready' => $dryRuns->where('ready', true)->count(),
                'blocked' => $dryRuns->where('ready', false)->count(),
                'operational' => $dryRuns->where('operational_classification', 'operational')->count(),
                'pre_operational' => $dryRuns->where('operational_classification', 'pre_operational')->count(),
                'operational_start_pending' => $dryRuns->where('operational_classification', 'operational_start_pending')->count(),
                'blocker_codes' => $dryRuns->flatMap(fn (array $row) => collect($row['blockers'])->pluck('code'))->countBy()->all(),
                'planned_items' => $dryRuns->sum('items'),
                'planned_payments' => $dryRuns->sum('payments'),
                'planned_movements' => $dryRuns->sum('movements'),
            ],
            'high_confidence' => $highConfidence,
            'missing_products' => collect($catalog['missing_products']),
            'categories' => ProductCategory::query()->where('active', true)->orderBy('name')->get(),
            'category_blocked' => $categoryBlocked,
            'operational_start_set' => $operationalStartSet,
            'operational_start_at' => $connection->operational_start_at?->setTimezone(config('app.timezone', 'America/Sao_Paulo')),
            'stock_inventory' => $stockInventory,
            'opening_stock_pending' => $openingStockPending,
            'stock_deficits' => $stockDeficits,
            'gate_reasons' => $gateReasons,
            'can_enable_import' => $canEnableImport,
            'import_enabled' => $importEnabled,
            'can_execute_import' => $canEnableImport && $importEnabled,
            'steps' => [
                $this->step(1, 'Produtos', $summary['products_without_candidate'] === 0, $categoryBlocked > 0, "{$summary['products_distinct']} externos · {$summary['products_without_candidate']} cadastros pendentes"),
                $this->step(2, 'Mapeamentos', $productsPending === 0 && $summary['products_distinct'] > 0, false, "{$summary['products_mapped']}/{$summary['products_distinct']} confirmados"),
                $this->step(3, 'Pagamentos', $paymentsPending === 0 && $summary['payments_unsupported'] === 0 && $summary['payments_distinct'] > 0, false, "{$summary['payments_mapped']}/{$summary['payments_distinct']} confirmados"),
                $this->step(4, 'Taxas', $summary['payments_configuration_missing'] === 0 && $summary['payments_rate_missing'] === 0 && $summary['payments_mapped'] === $summary['payments_distinct'], false, "{$summary['payments_configuration_missing']} configurações e {$summary['payments_rate_missing']} taxas pendentes"),
                $this->step(5, 'Marco oficial', $operationalStartSet, false, $operationalStartSet ? $connection->operational_start_at->setTimezone(config('app.timezone'))->format('d/m/Y H:i') : 'Data e hora ainda não definidas'),
                $this->step(6, 'Estoque inicial', $stockInventory->isNotEmpty() && $openingStockPending === 0, $summary['products_mapped'] === 0, $openingStockPending.' produto(s) pendente(s)'),
                $this->step(7, 'Conferência', $allPlansReady, false, $dryRuns->where('ready', true)->count().'/'.$dryRuns->count().' pedidos READY'),
                ['number' => 8, 'label' => 'Importação', 'status' => $canEnableImport && $importEnabled ? 'ready' : 'blocked', 'detail' => ! $canEnableImport ? 'Bloqueada pelos gates anteriores' : ($importEnabled ? 'Liberada somente pedido a pedido' : 'Pronta para decisão humana; feature flag permanece OFF')],
            ],
            'checklist' => [
                ['label' => 'Products oficiais revisados', 'derived' => $summary['products_without_candidate'] === 0],
                ['label' => 'Mappings de produto confirmados', 'derived' => $productsPending === 0 && $summary['products_distinct'] > 0],
                ['label' => 'Pagamentos e taxas vigentes', 'derived' => $paymentsPending === 0 && $summary['payments_configuration_missing'] === 0 && $summary['payments_rate_missing'] === 0],
                ['label' => 'Marco oficial de início definido', 'derived' => $operationalStartSet],
                ['label' => 'Estoque físico lançado pelo fluxo oficial', 'derived' => $stockInventory->isNotEmpty() && $openingStockPending === 0],
                ['label' => 'Todos os pedidos aprovados no dry-run', 'derived' => $allPlansReady],
                ['label' => 'Backup PostgreSQL criado, listado e testado em banco separado', 'derived' => null],
                ['label' => 'Primeira importação limitada a um pedido', 'derived' => (bool) config('pdv.first_import_single_order', true)],
            ],
        ];
    }

    /** @param Collection<int,array<string,mixed>> $products @return Collection<int,array<string,mixed>> */
    private function stockInventory(PdvConnection $connection, Collection $products): Collection
    {
        return $products
            ->filter(fn (array $row): bool => $row['mapping_status'] === 'confirmed' && $row['mapping']?->product !== null)
            ->groupBy(fn (array $row): int => $row['mapping']->product->id)
            ->map(function (Collection $rows) use ($connection): array {
                $product = $rows->first()['mapping']->product;
                $lastMovement = StockMovement::query()
                    ->where('product_id', $product->id)
                    ->where('location_id', $connection->location_id)
                    ->latest('operation_date')->latest('id')->first();
                $operationalRequired = $rows->reduce(
                    fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['operational_quantity_total']),
                    BigDecimal::zero(),
                );
                $historicalQuantity = $rows->reduce(
                    fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['historical_quantity_total']),
                    BigDecimal::zero(),
                );

                return [
                    'product' => $product,
                    'balance' => $this->balances->balance($product->id, (int) $connection->location_id),
                    'opening_stock_recorded' => StockMovement::query()
                        ->where('product_id', $product->id)
                        ->where('location_id', $connection->location_id)
                        ->where('type', StockMovementType::OpeningBalance->value)
                        ->exists(),
                    'last_movement' => $lastMovement,
                    'operational_required' => (string) $operationalRequired->toScale(6),
                    'historical_quantity' => (string) $historicalQuantity->toScale(6),
                ];
            })
            ->sortBy(fn (array $row): string => $row['product']->name)
            ->values();
    }

    private function reason(Collection $reasons, bool $condition, string $code, string $message): void
    {
        if ($condition) {
            $reasons->push(compact('code', 'message'));
        }
    }

    /** @return array{number:int,label:string,status:string,detail:string} */
    private function step(int $number, string $label, bool $ready, bool $blocked, string $detail): array
    {
        return ['number' => $number, 'label' => $label, 'status' => $ready ? 'ready' : ($blocked ? 'blocked' : 'pending'), 'detail' => $detail];
    }
}
