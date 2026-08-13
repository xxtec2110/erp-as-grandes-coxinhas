<?php

namespace App\Agent;

use App\Services\CreatePayableService;
use App\Services\CreatePurchaseDocumentService;
use App\Services\FinanceQueryService;
use App\Services\FinanceReportService;
use App\Services\OperationalSummaryService;
use App\Services\ProductionQueryService;
use App\Services\ProductionRequirementService;
use App\Services\ProductionService;
use App\Services\PurchaseDocumentActionService;
use App\Services\PurchaseQueryService;
use App\Services\RegisterPaymentService;
use App\Services\StockPositionService;
use App\Services\StockTransferQueryService;
use App\Services\UndoLastOperationService;

class AgentToolRegistry
{
    public function all(): array
    {
        return collect([
            new AgentToolDefinition('stock.positions.list', 'stock.view', true, false, false, ['location_id' => 'integer'], ['items' => 'array'], StockPositionService::class),
            new AgentToolDefinition('production.today', 'production.view', true, false, false, ['location_id' => 'integer', 'date' => 'date'], ['items' => 'array'], ProductionQueryService::class),
            new AgentToolDefinition('production.suggestions.list', 'production_requirements.view', true, false, false, ['location_id' => 'integer'], ['items' => 'array'], ProductionRequirementService::class),
            new AgentToolDefinition('production.plan', 'production.create', true, true, true, ['product_id' => 'integer', 'location_id' => 'integer', 'planned_quantity' => 'decimal', 'operation_date' => 'date', 'idempotency_key' => 'string'], ['id' => 'integer'], ProductionService::class),
            new AgentToolDefinition('transfers.list', 'transfers.view', true, false, false, ['location_id' => 'integer', 'status' => 'string'], ['items' => 'array'], StockTransferQueryService::class),
            new AgentToolDefinition('reports.operational.summary', 'reports.view', true, false, false, ['location_id' => 'integer', 'period' => 'string'], ['summary' => 'object'], OperationalSummaryService::class),
            new AgentToolDefinition('finance.payables.list', 'finance.payables.view', true, false, false, ['period' => 'string', 'location_id' => 'integer|null', 'supplier' => 'string|null'], ['items' => 'array'], FinanceQueryService::class),
            new AgentToolDefinition('finance.payables.get', 'finance.payables.view', true, false, false, ['id' => 'integer'], ['payable' => 'object'], FinanceQueryService::class),
            new AgentToolDefinition('finance.payables.create', 'finance.payables.create', true, true, true, ['location_id' => 'integer', 'amount' => 'decimal'], ['id' => 'integer'], CreatePayableService::class),
            new AgentToolDefinition('finance.payments.record', 'finance.payments.create', true, true, true, ['payable_id' => 'integer', 'amount' => 'decimal'], ['id' => 'integer'], RegisterPaymentService::class),
            new AgentToolDefinition('finance.payments.list', 'finance.payments.view', true, false, false, ['supplier' => 'string|null', 'account' => 'string|null', 'payer' => 'string|null'], ['items' => 'array'], FinanceQueryService::class),
            new AgentToolDefinition('finance.accounts.list', 'finance.accounts.view', true, false, false, [], ['items' => 'array'], FinanceReportService::class),
            new AgentToolDefinition('finance.reports.summary', 'finance.reports.view', true, false, false, ['period' => 'string'], ['summary' => 'object'], FinanceReportService::class),
            new AgentToolDefinition('purchases.documents.create', 'purchases.create', true, true, true, ['location_id' => 'integer', 'total_amount' => 'decimal'], ['id' => 'integer'], CreatePurchaseDocumentService::class),
            new AgentToolDefinition('purchases.documents.list', 'purchases.view', true, false, false, [], ['items' => 'array'], PurchaseQueryService::class),
            new AgentToolDefinition('purchases.documents.get', 'purchases.view', true, false, false, ['id' => 'integer'], ['document' => 'object'], PurchaseQueryService::class),
            new AgentToolDefinition('purchases.items.list', 'purchases.view', true, false, false, ['document_id' => 'integer'], ['items' => 'array'], PurchaseQueryService::class),
            new AgentToolDefinition('purchases.link_supplier', 'purchases.approve', true, true, true, ['document_id' => 'integer', 'supplier_id' => 'integer'], ['document' => 'object'], PurchaseDocumentActionService::class),
            new AgentToolDefinition('purchases.suggest_ingredient_price_update', 'ingredient_prices.update', true, true, true, ['item_id' => 'integer'], ['suggestion' => 'object'], PurchaseDocumentActionService::class),
            new AgentToolDefinition('agent.operations.undo', 'agent.operations.undo', true, true, true, ['operation_type' => 'string', 'operation_id' => 'integer', 'location_id' => 'integer', 'reason' => 'string'], ['id' => 'integer'], UndoLastOperationService::class),
        ])->keyBy('name')->all();
    }

    public function get(string $name): ?AgentToolDefinition
    {
        return $this->all()[$name] ?? null;
    }
}
