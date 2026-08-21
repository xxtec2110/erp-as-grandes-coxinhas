<?php

namespace App\Services;

use App\Models\PdvConnection;
use App\Models\PdvOrder;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class PdvOperationalReadinessService
{
    /** @param array<string,mixed> $catalog @param array<string,mixed> $readiness
     * @return array<string,mixed>
     */
    public function build(PdvConnection $connection, CarbonImmutable $from, CarbonImmutable $to, array $catalog, array $readiness): array
    {
        $summary = $catalog['summary'];
        $readinessSummary = $readiness['summary'];
        $productsPending = $summary['products_distinct'] - $summary['products_mapped'];
        $paymentsSupported = $summary['payments_distinct'] - $summary['payments_unsupported'];
        $paymentsPending = $summary['payments_distinct'] - $summary['payments_mapped'];
        $stockDeficits = collect($catalog['stock_preview'])->filter(fn (array $row): bool => BigDecimal::of($row['deficit'])->isPositive());
        $simulation = $this->simulateExactMappings($connection, $from, $to, $catalog['products']);

        return [
            'steps' => [
                ['number' => 1, 'label' => 'Produtos', 'status' => $productsPending === 0 && $summary['products_without_candidate'] === 0 ? 'ready' : 'attention', 'detail' => "{$summary['products_mapped']} mapeados · {$summary['products_without_candidate']} Products faltantes · {$productsPending} mappings pendentes"],
                ['number' => 2, 'label' => 'Pagamentos', 'status' => $paymentsPending === 0 && $summary['payments_configuration_missing'] === 0 ? 'ready' : 'attention', 'detail' => "{$paymentsSupported} suportados · {$paymentsPending} mappings pendentes · {$summary['payments_configuration_missing']} sem configuração financeira"],
                ['number' => 3, 'label' => 'Estoque', 'status' => $stockDeficits->isEmpty() && $summary['products_mapped'] > 0 ? 'ready' : 'attention', 'detail' => $summary['products_mapped'] === 0 ? 'Depende da confirmação humana dos mappings' : $stockDeficits->count().' Products com déficit'],
                ['number' => 4, 'label' => 'Importação', 'status' => $readinessSummary['ready'] === $readinessSummary['staged'] && $readinessSummary['staged'] > 0 ? 'ready' : 'blocked', 'detail' => 'Permanece bloqueada; este incremento não importa vendas.'],
            ],
            'summary' => [
                'products_existing' => $summary['products_distinct'] - $summary['products_without_candidate'],
                'products_missing' => $summary['products_without_candidate'],
                'products_mapping_pending' => $productsPending,
                'payments_supported' => $paymentsSupported,
                'payments_unsupported' => $summary['payments_unsupported'],
                'payments_mapping_pending' => $paymentsPending,
                'payments_configuration_missing' => $summary['payments_configuration_missing'],
                'stock_identifiable' => collect($catalog['stock_preview'])->count(),
                'stock_deficits' => $stockDeficits->count(),
                'staged' => $readinessSummary['staged'],
                'ready' => $readinessSummary['ready'],
                'blocked' => $readinessSummary['blocked'],
            ],
            'simulation' => $simulation,
            'next_actions' => $this->nextActions($connection, $from, $to, $catalog, $readinessSummary),
        ];
    }

    /** @param Collection<int,array<string,mixed>> $products @return array<string,int> */
    private function simulateExactMappings(PdvConnection $connection, CarbonImmutable $from, CarbonImmutable $to, Collection $products): array
    {
        $coveredExternalIds = $products
            ->filter(fn (array $row): bool => $row['mapping_status'] === 'confirmed' || in_array($row['suggestion']['type'], ['exact', 'alias'], true))
            ->pluck('external_product_id');
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $orders = PdvOrder::query()->whereBelongsTo($connection, 'connection')
            ->whereBetween('external_completed_at', [$from->setTimezone($timezone)->startOfDay()->utc(), $to->setTimezone($timezone)->endOfDay()->utc()])
            ->with(['items', 'payments'])->get();
        $stillMissingProducts = $orders->filter(fn (PdvOrder $order): bool => $order->items
            ->where('present_in_latest', true)->where('cancelled', false)
            ->contains(fn ($item): bool => ! $coveredExternalIds->contains($item->external_product_id)))->count();
        $pixOrders = $orders->filter(fn (PdvOrder $order): bool => $order->payments->where('present_in_latest', true)
            ->contains(fn ($payment): bool => str_contains(mb_strtolower((string) $payment->external_form_description.' '.(string) $payment->external_type), 'pix')))->count();

        return [
            'exact_or_alias_candidates' => $products->filter(fn (array $row): bool => in_array($row['suggestion']['type'], ['exact', 'alias'], true))->count(),
            'orders_product_ready_if_confirmed' => $orders->count() - $stillMissingProducts,
            'orders_still_missing_products' => $stillMissingProducts,
            'pix_orders_no_longer_unsupported' => $pixOrders,
            'payments_still_need_mapping' => $orders->count(),
        ];
    }

    /** @param array<string,mixed> $catalog @param array<string,mixed> $readinessSummary @return array<int,array<string,mixed>> */
    private function nextActions(PdvConnection $connection, CarbonImmutable $from, CarbonImmutable $to, array $catalog, array $readinessSummary): array
    {
        $summary = $catalog['summary'];
        $actions = [];
        if ($summary['products_exact'] + $summary['products_alias'] > 0) {
            $actions[] = ['priority' => 1, 'label' => 'Revisar mappings de alta confiança', 'detail' => ($summary['products_exact'] + $summary['products_alias']).' candidatos exatos/alias aguardam decisão humana.', 'url' => null];
        }
        if ($summary['products_without_candidate'] > 0) {
            $actions[] = ['priority' => 2, 'label' => 'Cadastrar Products oficiais faltantes', 'detail' => $summary['products_without_candidate'].' produtos externos precisam de onboarding humano.', 'url' => null];
        }
        if ($summary['payments_mapped'] < $summary['payments_distinct']) {
            $actions[] = ['priority' => 3, 'label' => 'Mapear formas de pagamento', 'detail' => ($summary['payments_distinct'] - $summary['payments_mapped']).' formas aguardam confirmação humana.', 'url' => null];
        }
        if ($summary['payments_configuration_missing'] > 0) {
            $actions[] = ['priority' => 4, 'label' => 'Configurar taxas de cartão', 'detail' => $summary['payments_configuration_missing'].' formas de cartão não possuem configuração financeira vigente.', 'url' => route('payment-fees.batch')];
        }
        if (collect($catalog['stock_preview'])->contains(fn (array $row): bool => BigDecimal::of($row['deficit'])->isPositive())) {
            $actions[] = ['priority' => 5, 'label' => 'Informar estoque inicial real', 'detail' => 'Use o fluxo oficial somente após confirmar os mappings e levantar as quantidades físicas.', 'url' => route('stock.opening.create', ['location_id' => $connection->location_id])];
        }
        $actions[] = ['priority' => 6, 'label' => 'Revisar readiness final', 'detail' => $readinessSummary['blocked'].' pedidos continuam bloqueados no cálculo atual.', 'url' => route('pdv.mappings', [$connection, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'status' => 'unmapped'])];

        return $actions;
    }
}
