<?php

namespace App\Agent;

use App\Models\Product;
use App\Services\ProductMatchService;
use Illuminate\Support\Str;

class DeterministicCommandParser
{
    public function __construct(private ProductMatchService $products) {}

    public function parse(string $text): ?array
    {
        $trimmed = trim($text);
        $value = mb_strtoupper(Str::ascii($trimmed));

        if (in_array($value, ['QUERO CRIAR UM NOVO SABOR DE COXINHA', 'QUERO CRIAR UM NOVO SABOR', 'QUERO CADASTRAR UM NOVO PRODUTO'], true)) {
            return ['tool' => 'catalog.products.create', 'arguments' => []];
        }
        if (preg_match('/^(?:CRIE|CADASTRE)\s+(.+?)\s+(?:POR|A)\s+R?\$?\s*([0-9]+(?:[.,][0-9]{1,4})?)$/ui', $trimmed, $matches) === 1) {
            return ['tool' => 'catalog.products.create', 'arguments' => ['name' => trim($matches[1]), 'selling_price' => str_replace(',', '.', $matches[2])]];
        }
        if (preg_match('/^ALTERE\s+(.+?)\s+(?:PARA|A)\s+R?\$?\s*([0-9]+(?:[.,][0-9]{1,4})?)$/ui', $trimmed, $matches) === 1) {
            $needle = $this->products->normalize($matches[1]);
            $found = Product::query()->get()->filter(fn (Product $product) => $this->products->normalize($product->name) === $needle);
            if ($found->count() === 1) {
                return ['tool' => 'catalog.products.update_price', 'arguments' => ['product_id' => $found->first()->id, 'selling_price' => str_replace(',', '.', $matches[2])]];
            }
        }
        if (in_array($value, ['QUERO ADICIONAR UM NOVO FORNECEDOR', 'QUERO CADASTRAR UM FORNECEDOR'], true)) {
            return ['tool' => 'catalog.suppliers.create', 'arguments' => []];
        }
        if (preg_match('/^(?:CADASTRE|CRIE)(?: O INSUMO)?\s+(.+)$/ui', $trimmed, $matches) === 1 && str_contains($value, 'INSUMO')) {
            return ['tool' => 'catalog.ingredients.create', 'arguments' => ['name' => trim($matches[1])]];
        }
        if (in_array($value, ['QUERO CRIAR UM RECHEIO', 'QUERO CRIAR UM PREPARO'], true)) {
            return ['tool' => 'catalog.preparations.create', 'arguments' => []];
        }

        if (preg_match('/^LIBERA AUDIO PARA (.+)$/', $value, $matches) === 1) {
            return ['tool' => 'agent.access.permission.grant', 'arguments' => ['target_user_name' => trim($matches[1]), 'permission' => 'agent.audio.use']];
        }
        if (preg_match('/^(?:BLOQUEIA|REVOGA) AUDIO (?:DO|PARA) (.+)$/', $value, $matches) === 1) {
            return ['tool' => 'agent.access.permission.revoke', 'arguments' => ['target_user_name' => trim($matches[1]), 'permission' => 'agent.audio.use']];
        }

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
            $value === 'ESTOQUE DE INSUMOS' => ['tool' => 'ingredient_stock.positions.list'],
            in_array($value, ['INSUMOS EM FALTA', 'INSUMOS BAIXOS'], true) => ['tool' => 'ingredient_stock.shortages.list'],
            str_starts_with($value, 'ESTOQUE ') => ['tool' => 'stock.positions.list', 'location_name' => trim(mb_substr($trimmed, 8))],
            $value === 'PRODUCAO HOJE' => ['tool' => 'production.today', 'date' => now()->toDateString()],
            $value === 'PRODUCAO SUGERIDA' => ['tool' => 'production.suggestions.list'],
            $value === 'DOCUMENTOS RECENTES' => ['tool' => 'purchases.documents.list'],
            $value === 'TRANSFERENCIAS' => ['action' => 'submenu', 'submenu' => 'transfers'],
            $value === 'TRANSFERENCIAS RECENTES' => ['tool' => 'transfers.list', 'status' => 'recent'],
            $value === 'TRANSFERENCIAS EM TRANSITO' => ['tool' => 'transfers.list', 'status' => 'in_transit'],
            in_array($value, ['PENDENTES DE RECEBIMENTO', 'TRANSFERENCIAS PENDENTES DE RECEBIMENTO'], true) => ['tool' => 'transfers.list', 'status' => 'pending_receipt'],
            in_array($value, ['RELATORIO OPERACIONAL', 'RELATORIO DO DIA', 'RELATORIO HOJE'], true) => ['tool' => 'reports.operational.summary', 'period' => 'today'],
            $value === 'RELATORIO SEMANA' => ['tool' => 'reports.operational.summary', 'period' => 'week'],
            $value === 'RELATORIO QUINZENA' => ['tool' => 'reports.operational.summary', 'period' => 'fortnight'],
            $value === 'RELATORIO MES' => ['tool' => 'reports.operational.summary', 'period' => 'month'],
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
