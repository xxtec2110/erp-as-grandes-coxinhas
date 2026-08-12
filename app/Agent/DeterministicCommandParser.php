<?php

namespace App\Agent;

use App\Models\Product;

class DeterministicCommandParser
{
    public function parse(string $text): ?array
    {
        $value = mb_strtoupper(trim($text));

        if (preg_match('/^PRODUZIMOS\s+([0-9]+(?:[.,][0-9]+)?)\s+(.+)$/ui', trim($text), $matches) === 1) {
            $name = mb_strtolower(trim($matches[2]));
            $product = Product::query()->where('active', true)->get()->first(fn (Product $item) => mb_strtolower($item->name) === $name);
            if ($product !== null) {
                return ['tool' => 'production.plan', 'arguments' => ['product_id' => $product->id, 'planned_quantity' => str_replace(',', '.', $matches[1]), 'operation_date' => now()->toDateString()]];
            }
        }

        return match (true) {
            in_array($value, ['DESFAZ O ÚLTIMO', 'DESFAZ O ULTIMO', 'APAGA O ÚLTIMO QUE MANDEI', 'APAGA O ULTIMO QUE MANDEI', 'CANCELAR MEU ÚLTIMO PAGAMENTO', 'CANCELAR MEU ULTIMO PAGAMENTO', 'ESSE LANÇAMENTO ESTAVA ERRADO', 'ESSE LANCAMENTO ESTAVA ERRADO', 'EXCLUIR A ÚLTIMA INFORMAÇÃO', 'EXCLUIR A ULTIMA INFORMACAO'], true) => ['tool' => 'agent.operations.undo'],
            $value === 'ESTOQUE' => ['tool' => 'stock.positions.list'],
            str_starts_with($value, 'ESTOQUE ') => ['tool' => 'stock.positions.list', 'location_name' => trim(mb_substr($text, 8))],
            $value === 'PRODUÇÃO HOJE' || $value === 'PRODUCAO HOJE' => ['tool' => 'production.today'],
            $value === 'CONTAS A PAGAR' => ['tool' => 'finance.payables.list', 'period' => 'open'],
            $value === 'CONTAS VENCIDAS' => ['tool' => 'finance.payables.list', 'period' => 'overdue'],
            $value === 'CONTAS HOJE' => ['tool' => 'finance.payables.list', 'period' => 'today'],
            $value === 'CONTAS SEMANA' => ['tool' => 'finance.payables.list', 'period' => 'week'],
            $value === 'FINANCEIRO HOJE' => ['tool' => 'finance.reports.summary', 'period' => 'today'],
            in_array($value, ['FINANCEIRO MÊS', 'FINANCEIRO MES'], true) => ['tool' => 'finance.reports.summary', 'period' => 'month'],
            str_starts_with($value, 'FORNECEDOR ') => ['tool' => 'finance.payables.list', 'supplier' => trim(mb_substr($text, 11))],
            str_starts_with($value, 'PAGAMENTOS FORNECEDOR ') => ['tool' => 'finance.payments.list', 'supplier' => trim(mb_substr($text, 22))],
            str_starts_with($value, 'CONTA ') => ['tool' => 'finance.payments.list', 'account' => trim(mb_substr($text, 6))],
            str_starts_with($value, 'PAGADOR ') => ['tool' => 'finance.payments.list', 'payer' => trim(mb_substr($text, 8))],
            default => null,
        };
    }
}
