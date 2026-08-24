<?php

namespace App\Agent;

use App\Support\DecimalFormatter;
use Brick\Math\BigDecimal;

class AgentResponseTemplate
{
    public function ingredientStock(iterable $positions, string $location): string
    {
        $lines = ['📦 ESTOQUE DE INSUMOS', '', '🏭 '.$location, ''];
        foreach ($positions as $position) {
            $lines[] = $position['ingredient']->name.': '.DecimalFormatter::format($position['balance'], 3).' '.$position['ingredient']->base_unit;
        }
        if (count($lines) === 4) {
            $lines[] = 'Nenhum insumo cadastrado.';
        }

        return implode("\n", $lines);
    }

    public function ingredientShortages(iterable $items, string $location): string
    {
        $lines = ['⚠️ INSUMOS COM RISCO DE FALTA', '', '🏭 '.$location, ''];
        foreach ($items as $item) {
            $unit = $item['ingredient']->base_unit;
            $lines[] = $item['ingredient']->name;
            $lines[] = 'Disponível: '.DecimalFormatter::format($item['available'], 3).' '.$unit;
            $lines[] = 'Necessário: '.DecimalFormatter::format($item['required'], 3).' '.$unit;
            $lines[] = 'Falta: '.DecimalFormatter::format($item['missing'], 3).' '.$unit;
            $lines[] = '';
        }
        if (count($lines) === 4) {
            $lines[] = 'Nenhum risco de falta para as ordens planejadas.';
        }

        return implode("\n", $lines);
    }

    public function stock(iterable $positions, string $location): string
    {
        $lines = ['📦 ESTOQUE', '', '🏭 '.$location, ''];
        $totals = [];
        foreach ($positions as $position) {
            $lines[] = $position['product']->name.': '.DecimalFormatter::format($position['balance'], $position['product']->stock_unit === 'un' ? 0 : 3).' '.$position['product']->stock_unit;
            $unit = $position['product']->stock_unit;
            $totals[$unit] = (string) BigDecimal::of($totals[$unit] ?? '0')->plus($position['balance']);
        }
        $lines[] = '';
        $lines[] = '────────────';
        foreach ($totals as $unit => $total) {
            $lines[] = 'Total '.$unit.': '.DecimalFormatter::format($total, $unit === 'un' ? 0 : 3);
        }

        return implode("\n", $lines);
    }

    public function salesSummary(array $result): string
    {
        $lines = ['📊 VENDAS — '.$result['location']['name'], $this->periodLabel($result['period'])];
        if ((int) $result['sales_count'] === 0) {
            $lines[] = '';
            $lines[] = 'Nenhuma venda oficial encontrada no período.';

            return implode("\n", $lines);
        }
        $lines[] = '';
        $lines[] = 'Faturamento: R$ '.DecimalFormatter::format($result['revenue'], 2);
        $lines[] = 'Vendas/pedidos: '.$result['sales_count'];
        $lines[] = 'Itens vendidos: '.DecimalFormatter::format($result['quantity'], 3);
        $lines[] = 'Ticket médio: R$ '.DecimalFormatter::format($result['average_ticket'], 2);
        $lines[] = 'Descontos: R$ '.DecimalFormatter::format($result['discounts'], 2);

        return implode("\n", $lines);
    }

    public function productRanking(array $result): string
    {
        $lines = ['🏆 PRODUTOS MAIS VENDIDOS — '.$result['location']['name'], $this->periodLabel($result['period']), ''];
        foreach ($result['items'] as $item) {
            $lines[] = $item['rank'].'. '.$item['name'].' — '.DecimalFormatter::format($item['quantity'], 3).' — R$ '.DecimalFormatter::format($item['revenue'], 2);
        }
        if ($result['items'] === []) {
            $lines[] = 'Nenhuma venda oficial encontrada no período.';
        }

        return implode("\n", $lines);
    }

    public function paymentSummary(array $result): string
    {
        $title = '💳 PAGAMENTOS — '.$result['location']['name'];
        if ($result['filter'] !== null) {
            $title .= ' — '.$result['filter'];
        }
        $lines = [$title, $this->periodLabel($result['period']), '', 'Bruto: R$ '.DecimalFormatter::format($result['gross'], 2), 'Taxas: R$ '.DecimalFormatter::format($result['fees'], 2), 'Líquido: R$ '.DecimalFormatter::format($result['net'], 2)];
        foreach ($result['by_method'] as $method) {
            $lines[] = $method['method'].': R$ '.DecimalFormatter::format($method['gross'], 2);
        }
        if ($result['by_method'] === []) {
            $lines[] = 'Nenhum pagamento oficial encontrado.';
        }

        return implode("\n", $lines);
    }

    public function productStockQuery(array $result): string
    {
        $lines = ['📦 ESTOQUE DE PRODUTOS — '.$result['location']['name'], ''];
        foreach ($result['items'] as $item) {
            $lines[] = $item['name'].': '.DecimalFormatter::format($item['balance'], $item['unit'] === 'un' ? 0 : 3).' '.$item['unit'];
        }
        if ($result['items'] === []) {
            $lines[] = 'Nenhum saldo encontrado para a consulta.';
        }

        return implode("\n", $lines);
    }

    public function ingredientStockQuery(array $result): string
    {
        $lines = ['📦 ESTOQUE DE INSUMOS — '.$result['location']['name'], ''];
        foreach ($result['items'] as $item) {
            $lines[] = $item['name'].': '.DecimalFormatter::format($item['balance'], $item['unit'] === 'un' ? 0 : 3).' '.$item['unit'];
        }
        if ($result['items'] === []) {
            $lines[] = 'Insumo não cadastrado ou sem posição de estoque disponível.';
        }

        return implode("\n", $lines);
    }

    public function pdvHealth(array $result): string
    {
        $lines = ['🔌 GRANDCHEF — '.$result['location']['name'], ''];
        foreach ($result['connections'] as $connection) {
            $lines[] = 'Conexão #'.$connection['connection_id'].': '.($connection['enabled'] ? 'ativa' : 'inativa');
            $lines[] = 'Última sincronização: '.($connection['last_sync_at'] ?? 'não registrada');
            $lines[] = 'Staging: '.$connection['staged'].' · prontos: '.$connection['ready'].' · bloqueados: '.$connection['blocked'];
        }
        if ($result['connections'] === []) {
            $lines[] = 'Nenhuma conexão GrandChef configurada para esta unidade.';
        }

        return implode("\n", $lines);
    }

    public function pdvReconciliation(array $result): string
    {
        $lines = ['🔎 CONCILIAÇÃO PDV — '.$result['location']['name'], $this->periodLabel($result['period']), ''];
        foreach ($result['connections'] as $connection) {
            $summary = $connection['summary'];
            $lines[] = 'Pedidos externos: '.$summary['external_orders'];
            $lines[] = 'Importados: '.$summary['imported'].' · prontos: '.$summary['ready'].' · bloqueados: '.$summary['blocked'];
            $lines[] = 'Diferença: R$ '.DecimalFormatter::format($summary['difference'], 2);
            $lines[] = 'Inconsistências: '.$summary['inconsistencies'];
        }
        if ($result['connections'] === []) {
            $lines[] = 'Nenhuma conexão GrandChef configurada para esta unidade.';
        }

        return implode("\n", $lines);
    }

    public function catalogPrices(array $result): string
    {
        $lines = ['🏷️ CATÁLOGO / PREÇOS', ''];
        foreach ($result['items'] as $item) {
            $lines[] = $item['name'].': '.($item['price'] === null ? 'preço não configurado' : 'R$ '.DecimalFormatter::format($item['price'], 2));
        }
        if ($result['items'] === []) {
            $lines[] = 'Nenhum produto oficial encontrado.';
        }

        return implode("\n", $lines);
    }

    public function finance(array $summary): string
    {
        return "📋 FINANCEIRO\n\n🔴 Vencidas: R$ ".DecimalFormatter::format($summary['overdue'], 2)."\n🟡 Em aberto: R$ ".DecimalFormatter::format($summary['open'], 2)."\n🟢 Pagas: R$ ".DecimalFormatter::format($summary['paid'], 2);
    }

    public function payables(iterable $items, ?string $location = null): string
    {
        $lines = ['📋 CONTAS A PAGAR'];
        if ($location !== null) {
            $lines[] = '🏭 Unidade: '.$location;
        }
        $total = '0';
        foreach ($items as $item) {
            $remaining = (string) BigDecimal::of($item->expected_amount)->minus($item->paidAmount());
            $total = (string) BigDecimal::of($total)->plus($remaining);
            $overdue = $item->due_date->isPast() && ! in_array($item->status, ['paid', 'cancelled'], true);
            $lines[] = '';
            $lines[] = ($overdue ? '🔴 ' : '🟡 ').($item->supplier?->name ?? $item->description);
            $lines[] = '💰 R$ '.DecimalFormatter::format($remaining, 2);
            $lines[] = '📅 '.$item->due_date->format('d/m/Y').($overdue ? ' — Vencida' : '');
        }
        $lines[] = '';
        $lines[] = '────────────';
        $lines[] = '💰 Total em aberto: R$ '.DecimalFormatter::format($total, 2);

        return implode("\n", $lines);
    }

    public function payablePreview(array $payload): string
    {
        return "📄 NOVA CONTA\n\nDescrição: ".($payload['description'] ?? 'Não informada')."\n💰 R$ ".DecimalFormatter::format((string) ($payload['expected_amount'] ?? '0'), 2)."\n📅 ".($payload['due_date'] ?? 'Não informada')."\n\nConfirmar?";
    }

    public function productions(iterable $items, string $location, string $date): string
    {
        $lines = ['🏭 PRODUÇÃO DO DIA', '', $location.' — '.date('d/m/Y', strtotime($date)), ''];
        foreach ($items as $item) {
            $quantity = $item->actual_quantity ?? $item->planned_quantity;
            $lines[] = $item->product->name.': '.DecimalFormatter::format($quantity, $item->product->stock_unit === 'un' ? 0 : 3).' '.$item->product->stock_unit.' — '.$item->status->label();
        }

        if (count($lines) === 4) {
            $lines[] = 'Nenhuma produção registrada nesta data.';
        }

        return implode("\n", $lines);
    }

    public function productionSuggestions(iterable $items, string $location): string
    {
        $lines = ['📋 PRODUÇÃO SUGERIDA', '', $location, ''];
        foreach ($items as $item) {
            if (BigDecimal::of($item['requirement'])->isZero()) {
                continue;
            }
            $unit = $item['product']->stock_unit;
            $scale = $unit === 'un' ? 0 : 3;
            $lines[] = $item['product']->name;
            $lines[] = 'Estoque: '.DecimalFormatter::format($item['balance'], $scale).' '.$unit;
            $lines[] = 'Alvo: '.DecimalFormatter::format($item['target'], $scale).' '.$unit;
            $lines[] = 'Produzir: '.DecimalFormatter::format($item['requirement'], $scale).' '.$unit;
            $lines[] = '';
        }

        if (count($lines) === 4) {
            $lines[] = 'Nenhuma produção necessária para atingir o estoque-alvo.';
        }

        return implode("\n", $lines);
    }

    public function purchases(iterable $documents): string
    {
        $lines = ['🧾 DOCUMENTOS DE COMPRA RECENTES', ''];
        foreach ($documents as $document) {
            $number = $document->document_number ? ' — '.$document->document_number : '';
            $lines[] = '#'.$document->id.$number.' — '.($document->supplier?->name ?? 'Sem fornecedor');
            $lines[] = $document->location->name.' — R$ '.DecimalFormatter::format($document->total_amount, 2);
            $lines[] = '';
        }

        if (count($lines) === 2) {
            $lines[] = 'Nenhum documento encontrado.';
        }

        return implode("\n", $lines);
    }

    public function purchase(object $document): string
    {
        return "🧾 DOCUMENTO #{$document->id}\n\nFornecedor: ".($document->supplier?->name ?? 'Não informado')."\nUnidade: {$document->location->name}\nEmissão: {$document->issue_date->format('d/m/Y')}\nTotal: R$ ".DecimalFormatter::format($document->total_amount, 2)."\nItens: {$document->items->count()}";
    }

    public function purchaseItems(iterable $items): string
    {
        $lines = ['📦 ITENS DO DOCUMENTO', ''];
        foreach ($items as $item) {
            $lines[] = $item->description.': '.DecimalFormatter::format($item->quantity, 3).' '.$item->unit.' — R$ '.DecimalFormatter::format($item->total_price, 2);
        }

        if (count($lines) === 2) {
            $lines[] = 'Nenhum item cadastrado.';
        }

        return implode("\n", $lines);
    }

    public function transfers(iterable $items, string $location): string
    {
        $lines = ['🚚 TRANSFERÊNCIAS', '', $location, ''];
        foreach ($items as $transfer) {
            $lines[] = '#'.$transfer->id.' — '.$transfer->sourceLocation->name.' → '.$transfer->destinationLocation->name;
            $lines[] = $transfer->status->label().' — '.$transfer->operation_date->format('d/m/Y');
            foreach ($transfer->items as $item) {
                $lines[] = $item->product->name.': '.DecimalFormatter::format($item->quantity_sent, $item->product->stock_unit === 'un' ? 0 : 3).' '.$item->product->stock_unit;
            }
            $lines[] = '';
        }

        if (count($lines) === 4) {
            $lines[] = 'Nenhuma transferência encontrada.';
        }

        return implode("\n", $lines);
    }

    public function operational(array $summary, string $location): string
    {
        $lines = ['📊 RELATÓRIO OPERACIONAL', '', $location, ''];
        $hasMovement = false;
        foreach (['production' => 'Produção', 'entries' => 'Entradas', 'outbound' => 'Saídas', 'transfers' => 'Transferências', 'receipts' => 'Recebimentos', 'losses' => 'Perdas'] as $key => $label) {
            foreach ($summary[$key] ?? [] as $unit => $value) {
                $hasMovement = true;
                $lines[] = $label.': '.DecimalFormatter::format($value, $unit === 'un' ? 0 : 3).' '.$unit;
            }
        }
        $lines[] = '';
        $lines[] = '💰 Faturamento: R$ '.DecimalFormatter::format($summary['revenue']['brl'] ?? '0', 2);
        $lines[] = '💳 Taxas: R$ '.DecimalFormatter::format($summary['fees']['brl'] ?? '0', 2);

        if (! $hasMovement) {
            $lines[] = 'Nenhum movimento no período.';
        }

        return implode("\n", $lines);
    }

    private function periodLabel(array $period): string
    {
        return $period['from'] === $period['to'] ? $period['from'] : $period['from'].' a '.$period['to'];
    }
}
