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
}
