<?php

namespace App\Agent;

class AgentToolDefinition
{
    public function __construct(public string $name, public string $permission, public bool $locationScoped, public bool $confirmationRequired, public bool $writesData, public array $inputSchema, public array $outputSchema, public string $serviceClass) {}

    public function capabilityLabel(): string
    {
        return match (true) {
            str_starts_with($this->name, 'sales.') => 'Vendas',
            str_starts_with($this->name, 'stock.'), str_starts_with($this->name, 'ingredient_stock.') => 'Estoque',
            str_starts_with($this->name, 'production.') => 'Produção',
            str_starts_with($this->name, 'transfers.') => 'Transferências',
            str_starts_with($this->name, 'losses.') => 'Perdas',
            str_starts_with($this->name, 'purchases.') => 'Compras',
            str_starts_with($this->name, 'finance.') => 'Financeiro',
            str_starts_with($this->name, 'reports.') => 'Relatórios',
            str_starts_with($this->name, 'pdv.') => 'Integração PDV',
            str_starts_with($this->name, 'dashboard.') => 'Dashboard',
            str_starts_with($this->name, 'agent.access.') => 'Usuários e acessos',
            str_starts_with($this->name, 'agent.operations.') => 'Operações do agente',
            str_starts_with($this->name, 'catalog.'), str_starts_with($this->name, 'products.'),
            str_starts_with($this->name, 'ingredients.'), str_starts_with($this->name, 'suppliers.'),
            str_starts_with($this->name, 'costs.') => 'Cadastros e custos',
            default => 'Operações do ERP',
        };
    }
}
