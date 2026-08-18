<?php

namespace App\Services;

use DomainException;
use Illuminate\Support\Collection;

class DashboardWidgetRegistry
{
    /** @return Collection<int, array<string, mixed>> */
    public function all(): Collection
    {
        return collect([
            $this->widget('dashboard.revenue', 'Receita do período', 'Faturamento bruto confirmado no período selecionado.', 'indicators', 10, ['sales.view', 'dashboard.financial.view'], 'dashboard.widgets.metric', true, false, 'revenue'),
            $this->widget('dashboard.gross_profit', 'Lucro bruto realizado', 'Receita menos o custo preservado nas vendas.', 'indicators', 20, ['sales.view', 'dashboard.financial.view'], 'dashboard.widgets.metric', true, false, 'gross_profit'),
            $this->widget('dashboard.sales_quantity', 'Coxinhas vendidas', 'Quantidade oficial vendida na unidade e período.', 'indicators', 30, ['sales.view'], 'dashboard.widgets.metric', false, true, 'sales_quantity'),
            $this->widget('dashboard.gross_margin', 'Margem bruta realizada', 'Margem calculada somente quando todas as vendas possuem custo preservado.', 'indicators', 40, ['sales.view', 'dashboard.financial.view'], 'dashboard.widgets.metric', true, false, 'gross_margin'),
            $this->widget('dashboard.operational_summary', 'Resumo operacional', 'Produção, saídas, perdas, recebimentos e ordens da unidade.', 'operation', 100, ['reports.view'], 'dashboard.widgets.operational-summary', false, true, 'operational_summary', 'wide'),
            $this->widget('dashboard.daily_goal', 'Meta diária de coxinhas', 'Progresso real das vendas contra a meta configurada da unidade.', 'operation', 110, ['sales.view'], 'dashboard.widgets.daily-goal', false, true, 'daily_goal', 'wide'),
            $this->widget('dashboard.stock_balance', 'Saldo de estoque', 'Saldo oficial por produto na unidade selecionada.', 'operation', 120, ['stock.view'], 'dashboard.widgets.stock-balance', false, true, 'stock_balance', 'wide'),
            $this->widget('dashboard.flavor_performance', 'Desempenho dos sabores', 'Preço, custo atual, lucro unitário e margem dos produtos com ficha.', 'operation', 130, ['products.view', 'product_recipes.view', 'dashboard.financial.view'], 'dashboard.widgets.flavor-performance', true, false, 'flavor_performance', 'wide'),
            $this->widget('dashboard.top_flavors', 'Sabores mais vendidos', 'Ranking real por quantidade vendida na unidade e período.', 'operation', 140, ['sales.view'], 'dashboard.widgets.top-flavors', false, true, 'top_flavors'),
            $this->widget('dashboard.ingredient_price_variation', 'Variação de preço dos insumos', 'Histórico confirmado de custos e tendência no período.', 'costs', 200, ['ingredients.view', 'dashboard.financial.view'], 'dashboard.widgets.ingredient-variation', true, false, 'ingredient_price_variation', 'wide'),
            $this->widget('dashboard.accounts_payable', 'Contas a pagar', 'Total em aberto, vencido e próximo do vencimento.', 'finance', 300, ['finance.payables.view'], 'dashboard.widgets.accounts-payable', true, false, 'accounts_payable'),
            $this->widget('dashboard.upcoming_payables', 'Próximas contas a pagar', 'Títulos reais ordenados pelo vencimento.', 'finance', 310, ['finance.payables.view'], 'dashboard.widgets.upcoming-payables', true, false, 'upcoming_payables'),
            $this->widget('dashboard.recent_purchases', 'Compras recentes', 'Documentos oficiais mais recentes da unidade.', 'finance', 320, ['purchases.view'], 'dashboard.widgets.recent-purchases', true, false, 'recent_purchases'),
            $this->widget('dashboard.cash_flow', 'Fluxo de caixa identificado', 'Entradas líquidas de vendas e pagamentos registrados no período.', 'finance', 330, ['sales.view', 'finance.reports.view'], 'dashboard.widgets.cash-flow', true, false, 'cash_flow'),
            $this->widget('dashboard.operational_alerts', 'Alertas operacionais', 'Alertas derivados exclusivamente de regras e dados autorizados.', 'alerts', 400, [], 'dashboard.widgets.alerts', false, true, 'operational_alerts', 'wide'),
        ])->sortBy('order')->values();
    }

    /** @return array<string, string> */
    public function groups(): array
    {
        return [
            'indicators' => 'Indicadores',
            'operation' => 'Operação',
            'costs' => 'Custos',
            'finance' => 'Financeiro',
            'alerts' => 'Alertas',
        ];
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return $this->all()->pluck('key')->all();
    }

    /** @return array<string, mixed> */
    public function get(string $key): array
    {
        return $this->all()->firstWhere('key', $key)
            ?? throw new DomainException("Widget desconhecido: {$key}.");
    }

    /** @return array<string, mixed> */
    private function widget(string $key, string $name, string $description, string $group, int $order, array $permissions, string $view, bool $sensitive, bool $defaultVisible, string $provider, string $size = 'standard'): array
    {
        return compact('key', 'name', 'description', 'group', 'order', 'permissions', 'view', 'sensitive', 'defaultVisible', 'provider', 'size');
    }
}
