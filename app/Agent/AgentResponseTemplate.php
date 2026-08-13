<?php

namespace App\Agent;

use App\Support\DecimalFormatter;
use Brick\Math\BigDecimal;

class AgentResponseTemplate
{
    public function stock(iterable $positions, string $location): string
    {
        $lines = ['📦 ESTOQUE', '', '🏭 '.$location, ''];
        $total = '0';
        foreach ($positions as $position) {
            $lines[] = $position['product']->name.': '.DecimalFormatter::format($position['balance'], $position['product']->stock_unit === 'un' ? 0 : 3).' '.$position['product']->stock_unit;
            $total = (string) BigDecimal::of($total)->plus($position['balance']);
        }
        $lines[] = '';
        $lines[] = '────────────';
        $lines[] = 'Total: '.DecimalFormatter::format($total, 0);

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
        foreach (['production' => 'Produção', 'entries' => 'Entradas', 'outbound' => 'Saídas', 'transfers' => 'Transferências', 'receipts' => 'Recebimentos', 'losses' => 'Perdas'] as $key => $label) {
            foreach ($summary[$key] ?? [] as $unit => $value) {
                $lines[] = $label.': '.DecimalFormatter::format($value, $unit === 'un' ? 0 : 3).' '.$unit;
            }
        }

        if (count($lines) === 4) {
            $lines[] = 'Nenhum movimento no período.';
        }

        return implode("\n", $lines);
    }
}
