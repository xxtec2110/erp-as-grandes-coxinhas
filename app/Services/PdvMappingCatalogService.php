<?php

namespace App\Services;

use App\Models\PaymentFee;
use App\Models\PdvConnection;
use App\Models\PdvPaymentMethodMapping;
use App\Models\PdvProductMapping;
use App\Models\Product;
use App\Models\ProductCategory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PdvMappingCatalogService
{
    public function __construct(
        private PdvExternalProductSuggestionService $suggestions,
        private PdvPaymentCompatibilityService $payments,
        private PaymentFeeResolver $fees,
        private StockBalanceService $balances,
        private PdvProductOnboardingService $onboarding,
    ) {}

    /** @return array{products:Collection<int,array<string,mixed>>,payments:Collection<int,array<string,mixed>>,summary:array<string,mixed>,stock_preview:Collection<int,array<string,mixed>>,missing_products:Collection<int,array<string,mixed>>} */
    public function forPeriod(PdvConnection $connection, CarbonImmutable $from, CarbonImmutable $to, string $status = 'all'): array
    {
        [$fromUtc, $toUtc] = $this->utcPeriod($from, $to);
        $products = $this->products($connection, $fromUtc, $toUtc);
        $payments = $this->payments($connection, $fromUtc, $toUtc);
        $filteredProducts = $status === 'unmapped'
            ? $products->where('mapping_status', '!=', 'confirmed')->values()
            : $products;
        $stockPreview = $products
            ->filter(fn (array $row): bool => $row['stock_preview'] !== null)
            ->map(fn (array $row): array => $row['stock_preview'])
            ->values();

        return [
            'products' => $filteredProducts,
            'payments' => $payments,
            'summary' => [
                'products_distinct' => $products->count(),
                'products_mapped' => $products->where('mapping_status', 'confirmed')->count(),
                'products_exact' => $products->where('suggestion.type', PdvExternalProductSuggestionService::TYPE_EXACT)->count(),
                'products_alias' => $products->where('suggestion.type', PdvExternalProductSuggestionService::TYPE_ALIAS)->count(),
                'products_similar' => $products->where('suggestion.type', PdvExternalProductSuggestionService::TYPE_SIMILAR)->count(),
                'products_without_candidate' => $products->where('suggestion.type', PdvExternalProductSuggestionService::TYPE_NONE)->count(),
                'payments_distinct' => $payments->count(),
                'payments_mapped' => $payments->where('mapping_status', 'confirmed')->count(),
                'payments_unmapped' => $payments->where('mapping_status', '!=', 'confirmed')->where('compatibility.supported', true)->count(),
                'payments_unsupported' => $payments->where('compatibility.supported', false)->count(),
                'payments_rate_missing' => $payments->where('rate_missing', true)->count(),
                'payments_configuration_missing' => $payments->where('configuration_missing', true)->count(),
            ],
            'stock_preview' => $stockPreview,
            'missing_products' => $products->where('suggestion.type', PdvExternalProductSuggestionService::TYPE_NONE)->values(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function products(PdvConnection $connection, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): Collection
    {
        $erpProducts = Product::query()->with(['category', 'aliases'])->orderBy('name')->get();
        $categories = ProductCategory::query()->where('active', true)->orderBy('name')->get();
        $mappings = PdvProductMapping::query()
            ->whereBelongsTo($connection, 'connection')
            ->with(['product.category', 'product.aliases'])
            ->get()
            ->keyBy('external_product_id');
        $stock = [];
        $priceObservations = DB::table('pdv_order_items as items')
            ->join('pdv_orders as orders', 'orders.id', '=', 'items.pdv_order_id')
            ->where('orders.pdv_connection_id', $connection->id)
            ->whereBetween('orders.external_completed_at', [$fromUtc, $toUtc])
            ->where('items.present_in_latest', true)
            ->where('items.cancelled', false)
            ->whereNotNull('items.external_product_id')
            ->orderByDesc('orders.external_completed_at')->orderByDesc('items.id')
            ->get(['items.external_product_id', 'items.unit_price'])
            ->groupBy('external_product_id');
        $operationalQuantities = collect();
        if ($connection->operational_start_at !== null) {
            $operationalFrom = CarbonImmutable::instance($connection->operational_start_at)->utc();
            if ($operationalFrom->lessThanOrEqualTo($toUtc)) {
                $eligibleFrom = $operationalFrom->greaterThan($fromUtc) ? $operationalFrom : $fromUtc;
                $operationalQuantities = DB::table('pdv_order_items as items')
                    ->join('pdv_orders as orders', 'orders.id', '=', 'items.pdv_order_id')
                    ->where('orders.pdv_connection_id', $connection->id)
                    ->whereBetween('orders.external_completed_at', [$eligibleFrom, $toUtc])
                    ->where('items.present_in_latest', true)
                    ->where('items.cancelled', false)
                    ->whereNotNull('items.external_product_id')
                    ->groupBy('items.external_product_id')
                    ->selectRaw('items.external_product_id, SUM(items.quantity) AS quantity_total')
                    ->get()
                    ->keyBy('external_product_id');
            }
        }

        return DB::table('pdv_order_items as items')
            ->join('pdv_orders as orders', 'orders.id', '=', 'items.pdv_order_id')
            ->where('orders.pdv_connection_id', $connection->id)
            ->whereBetween('orders.external_completed_at', [$fromUtc, $toUtc])
            ->where('items.present_in_latest', true)
            ->where('items.cancelled', false)
            ->whereNotNull('items.external_product_id')
            ->groupBy('items.external_product_id')
            ->orderByRaw('MAX(items.description)')
            ->selectRaw('items.external_product_id, MAX(items.external_product_code) AS external_product_code, MAX(items.description) AS description, COUNT(*) AS line_count, SUM(items.quantity) AS quantity_total, SUM(items.total) AS value_total, COUNT(DISTINCT items.pdv_order_id) AS order_count, MIN(orders.external_completed_at) AS first_appearance, MAX(orders.external_completed_at) AS last_appearance')
            ->get()
            ->map(function (object $row) use ($connection, $erpProducts, $categories, $mappings, $priceObservations, $operationalQuantities, &$stock): array {
                $mapping = $mappings->get($row->external_product_id);
                $suggestion = $this->suggestions->suggest((string) $row->description, $erpProducts);
                $mappingStatus = $mapping?->status === 'confirmed' && $mapping->product_id !== null ? 'confirmed' : ($mapping?->status ?? 'unmapped');
                // Estoque físico só pode ser associado depois de um mapping humano confirmado.
                // Sugestões exatas/alias continuam sendo apenas auxiliares de decisão.
                $stockProduct = $mappingStatus === 'confirmed' ? $mapping?->product : null;
                $stockPreview = null;
                $operationalQuantity = BigDecimal::of((string) ($operationalQuantities->get($row->external_product_id)?->quantity_total ?? '0'))->toScale(6, RoundingMode::HalfUp);
                $historicalQuantity = BigDecimal::of((string) $row->quantity_total)->minus($operationalQuantity)->toScale(6, RoundingMode::HalfUp);
                if ($stockProduct !== null && $operationalQuantity->isPositive()) {
                    $available = $stock[$stockProduct->id] ??= $this->balances->balance($stockProduct->id, (int) $connection->location_id);
                    $required = $operationalQuantity;
                    $deficit = BigDecimal::of($available)->minus($required);
                    $deficit = $deficit->isNegative() ? $deficit->abs() : BigDecimal::zero();
                    $stockPreview = [
                        'product' => $stockProduct,
                        'source' => 'mapping_confirmed',
                        'required' => (string) $required,
                        'available' => (string) BigDecimal::of($available)->toScale(6, RoundingMode::HalfUp),
                        'deficit' => (string) $deficit->toScale(6, RoundingMode::HalfUp),
                        'opening_stock_available' => $mappingStatus === 'confirmed' && $deficit->isPositive(),
                    ];
                }

                return [
                    'external_product_id' => (string) $row->external_product_id,
                    'external_product_code' => $row->external_product_code === null ? null : (string) $row->external_product_code,
                    'description' => (string) $row->description,
                    'line_count' => (int) $row->line_count,
                    'quantity_total' => (string) $row->quantity_total,
                    'operational_quantity_total' => (string) $operationalQuantity,
                    'historical_quantity_total' => (string) $historicalQuantity,
                    'value_total' => (string) $row->value_total,
                    'order_count' => (int) $row->order_count,
                    'first_appearance' => CarbonImmutable::parse((string) $row->first_appearance),
                    'last_appearance' => CarbonImmutable::parse((string) $row->last_appearance),
                    'mapping' => $mapping,
                    'mapping_status' => $mappingStatus,
                    'suggestion' => $suggestion,
                    'stock_preview' => $stockPreview,
                    'prices' => $this->onboarding->priceDetails($priceObservations->get($row->external_product_id, collect())),
                    'suggested_name' => $this->onboarding->suggestedName((string) $row->description),
                    'suggested_category' => $this->onboarding->suggestedCategory((string) $row->description, $categories),
                    'category_gate' => $this->onboarding->isBeverage((string) $row->description)
                        && $this->onboarding->suggestedCategory((string) $row->description, $categories) === null,
                ];
            })->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function payments(PdvConnection $connection, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): Collection
    {
        $mappings = PdvPaymentMethodMapping::query()
            ->whereBelongsTo($connection, 'connection')
            ->with(['acquirer', 'cardBrand'])
            ->get()
            ->keyBy('external_method_code');
        $fees = PaymentFee::query()->with(['acquirer', 'cardBrand'])->where('active', true)->where('is_current', true)->get();

        return DB::table('pdv_order_payments as payments')
            ->join('pdv_orders as orders', 'orders.id', '=', 'payments.pdv_order_id')
            ->where('orders.pdv_connection_id', $connection->id)
            ->whereBetween('orders.external_completed_at', [$fromUtc, $toUtc])
            ->where('payments.present_in_latest', true)
            ->whereNotNull('payments.external_form_id')
            ->groupBy('payments.external_form_id', 'payments.external_form_description', 'payments.external_type')
            ->orderBy('payments.external_form_description')
            ->selectRaw('payments.external_form_id, payments.external_form_description, payments.external_type, COUNT(*) AS occurrence_count, SUM(payments.amount) AS amount_total, COUNT(DISTINCT payments.pdv_order_id) AS order_count')
            ->get()
            ->map(function (object $row) use ($connection, $fromUtc, $toUtc, $mappings, $fees): array {
                $mapping = $mappings->get($row->external_form_id);
                $compatibility = $this->payments->forExternal($row->external_form_description, $row->external_type);
                $mappingStatus = $mapping?->status === 'confirmed' && $mapping->payment_method !== null ? 'confirmed' : ($mapping?->status ?? 'unmapped');
                $rateMissing = $mappingStatus === 'confirmed'
                    && in_array($mapping?->payment_method, ['debit', 'credit'], true)
                    && ! $this->hasRateCoverage($connection, (string) $row->external_form_id, $mapping, $fromUtc, $toUtc);
                $financialOptions = $compatibility['requires_rate']
                    ? $fees->where('payment_method', $compatibility['method'])
                        ->filter(fn (PaymentFee $fee): bool => $fee->acquirer?->active && $fee->cardBrand?->active && $this->hasRateCoverageFor($connection, (string) $row->external_form_id, $fee->acquirer_id, $fee->card_brand_id, (string) $compatibility['method'], $fromUtc, $toUtc))
                        ->unique(fn (PaymentFee $fee): string => $fee->acquirer_id.':'.$fee->card_brand_id)
                        ->values()
                    : collect();

                return [
                    'external_form_id' => (string) $row->external_form_id,
                    'external_form_description' => $row->external_form_description === null ? null : (string) $row->external_form_description,
                    'external_type' => $row->external_type === null ? null : (string) $row->external_type,
                    'occurrence_count' => (int) $row->occurrence_count,
                    'amount_total' => (string) $row->amount_total,
                    'order_count' => (int) $row->order_count,
                    'mapping' => $mapping,
                    'mapping_status' => $mappingStatus,
                    'compatibility' => $compatibility,
                    'rate_missing' => $rateMissing,
                    'financial_options' => $financialOptions,
                    'configuration_missing' => $compatibility['requires_rate'] && $financialOptions->isEmpty(),
                ];
            })->values();
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function utcPeriod(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');

        return [$from->setTimezone($timezone)->startOfDay()->utc(), $to->setTimezone($timezone)->endOfDay()->utc()];
    }

    private function hasRateCoverage(PdvConnection $connection, string $externalFormId, PdvPaymentMethodMapping $mapping, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): bool
    {
        if ($mapping->acquirer_id === null || $mapping->card_brand_id === null || $mapping->payment_method === null) {
            return false;
        }

        return $this->hasRateCoverageFor($connection, $externalFormId, $mapping->acquirer_id, $mapping->card_brand_id, $mapping->payment_method, $fromUtc, $toUtc);
    }

    private function hasRateCoverageFor(PdvConnection $connection, string $externalFormId, int $acquirerId, int $cardBrandId, string $method, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): bool
    {
        return DB::table('pdv_order_payments as payments')
            ->join('pdv_orders as orders', 'orders.id', '=', 'payments.pdv_order_id')
            ->where('orders.pdv_connection_id', $connection->id)
            ->whereBetween('orders.external_completed_at', [$fromUtc, $toUtc])
            ->where('payments.present_in_latest', true)
            ->where('payments.external_form_id', $externalFormId)
            ->get(['payments.installments', 'orders.external_completed_at'])
            ->every(fn (object $payment): bool => $this->fees->resolve(
                $acquirerId,
                $cardBrandId,
                $method,
                $payment->installments === null ? null : (int) $payment->installments,
                CarbonImmutable::parse((string) $payment->external_completed_at)->setTimezone(config('app.timezone'))->toDateString(),
            ) !== null);
    }
}
