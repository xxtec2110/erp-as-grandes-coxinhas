<?php

namespace App\Agent;

use App\Models\Product;
use Illuminate\Support\Str;

class DeterministicCommandParser
{
    public function parse(string $text): ?array
    {
        $trimmed = trim($text);
        $value = mb_strtoupper(Str::ascii($trimmed));

        if (preg_match('/^PRODUZIMOS\s+([0-9]+(?:[.,][0-9]+)?)\s+(.+)$/ui', $trimmed, $matches) === 1) {
            $name = mb_strtolower(trim($matches[2]));
            $product = Product::query()->where('active', true)->get()
                ->first(fn (Product $item) => mb_strtolower($item->name) === $name);

            if ($product !== null) {
                return [
                    'tool' => 'production.plan',
                    'arguments' => [
                        'product_id' => $product->id,
                        'planned_quantity' => str_replace(',', '.', $matches[1]),
                        'operation_date' => now()->toDateString(),
                    ],
                ];
            }
        }

        if (preg_match('/^DOCUMENTO\s+(\d+)$/', $value, $matches) === 1) {
            return ['tool' => 'purchases.documents.get', 'arguments' => ['id' => (int) $matches[1]]];
        }

        if (preg_match('/^ITENS\s+DOCUMENTO\s+(\d+)$/', $value, $matches) === 1) {
            return ['tool' => 'purchases.items.list', 'arguments' => ['document_id' => (int) $matches[1]]];
        }

        if (preg_match('/^FORNECEDOR\s+(.+)$/ui', $trimmed, $matches) === 1) {
            return ['tool' => 'finance.payables.list', 'supplier' => trim($matches[1])];
        }

        if (preg_match('/^PAGAMENTOS\s+FORNECEDOR\s+(.+)$/ui', $trimmed, $matches) === 1) {
            return ['tool' => 'finance.payments.list', 'supplier' => trim($matches[1])];
        }

        if (preg_match('/^CONTA\s+(.+)$/ui', $trimmed, $matches) === 1) {
            return ['tool' => 'finance.payments.list', 'account' => trim($matches[1])];
        }

        if (preg_match('/^PAGADOR\s+(.+)$/ui', $trimmed, $matches) === 1) {
            return ['tool' => 'finance.payments.list', 'payer' => trim($matches[1])];
        }

        return match (true) {
            in_array($value, [
                'DESFAZ O ULTIMO', 'APAGA O ULTIMO QUE MANDEI', 'CANCELAR MEU ULTIMO PAGAMENTO',
                'ESSE LANCAMENTO ESTAVA ERRADO', 'EXCLUIR A ULTIMA INFORMACAO',
            ], true) => ['tool' => 'agent.operations.undo'],
            $value === 'PRODUCAO' => ['action' => 'submenu', 'submenu' => 'production'],
            $value === 'COMPRAS' => ['action' => 'submenu', 'submenu' => 'purchases'],
            $value === 'FINANCEIRO' => ['action' => 'submenu', 'submenu' => 'finance'],
            $value === 'ESTOQUE' => ['tool' => 'stock.positions.list'],
            str_starts_with($value, 'ESTOQUE ') => ['tool' => 'stock.positions.list', 'location_name' => trim(mb_substr($trimmed, 8))],
            $value === 'PRODUCAO HOJE' => ['tool' => 'production.today', 'date' => now()->toDateString()],
            $value === 'PRODUCAO SUGERIDA' => ['tool' => 'production.suggestions.list'],
            $value === 'DOCUMENTOS RECENTES' => ['tool' => 'purchases.documents.list'],
            $value === 'TRANSFERENCIAS' => ['action' => 'submenu', 'submenu' => 'transfers'],
            $value === 'TRANSFERENCIAS RECENTES' => ['tool' => 'transfers.list', 'status' => 'recent'],
            $value === 'TRANSFERENCIAS EM TRANSITO' => ['tool' => 'transfers.list', 'status' => 'in_transit'],
            in_array($value, ['PENDENTES DE RECEBIMENTO', 'TRANSFERENCIAS PENDENTES DE RECEBIMENTO'], true) => ['tool' => 'transfers.list', 'status' => 'pending_receipt'],
            $value === 'RELATORIO OPERACIONAL' => ['tool' => 'reports.operational.summary', 'period' => 'today'],
            $value === 'CONTAS A PAGAR' => ['tool' => 'finance.payables.list', 'period' => 'open'],
            $value === 'CONTAS VENCIDAS' => ['tool' => 'finance.payables.list', 'period' => 'overdue'],
            $value === 'CONTAS HOJE' => ['tool' => 'finance.payables.list', 'period' => 'today'],
            $value === 'CONTAS SEMANA' => ['tool' => 'finance.payables.list', 'period' => 'week'],
            $value === 'FINANCEIRO HOJE' => ['tool' => 'finance.reports.summary', 'period' => 'today'],
            $value === 'FINANCEIRO MES' => ['tool' => 'finance.reports.summary', 'period' => 'month'],
            default => null,
        };
    }
}
