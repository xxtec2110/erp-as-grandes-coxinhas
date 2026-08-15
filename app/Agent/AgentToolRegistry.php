<?php

namespace App\Agent;

use App\Services\AgentAccessManagementService;
use App\Services\CreatePayableService;
use App\Services\CreatePurchaseDocumentService;
use App\Services\FinanceQueryService;
use App\Services\FinanceReportService;
use App\Services\IngredientShortageService;
use App\Services\IngredientStockPositionService;
use App\Services\OperationalSummaryService;
use App\Services\ProductionOrderService;
use App\Services\ProductionQueryService;
use App\Services\ProductionRequirementService;
use App\Services\ProductionService;
use App\Services\ProductLossService;
use App\Services\PurchaseDocumentActionService;
use App\Services\PurchaseQueryService;
use App\Services\RegisterPaymentService;
use App\Services\StockPositionService;
use App\Services\StockTransferQueryService;
use App\Services\StockTransferService;
use App\Services\UndoLastOperationService;

class AgentToolRegistry
{
    public function all(): array
    {
        return collect([
            new AgentToolDefinition('agent.access.permission.grant', 'users.manage', false, true, true, ['target_user_name' => 'string', 'permission' => 'string'], ['id' => 'integer'], AgentAccessManagementService::class),
            new AgentToolDefinition('agent.access.permission.revoke', 'users.manage', false, true, true, ['target_user_name' => 'string', 'permission' => 'string'], ['id' => 'integer'], AgentAccessManagementService::class),
            new AgentToolDefinition('agent.access.location.grant', 'users.manage', false, true, true, ['target_user_name' => 'string', 'location_id' => 'integer'], ['id' => 'integer'], AgentAccessManagementService::class),
            new AgentToolDefinition('agent.access.location.revoke', 'users.manage', false, true, true, ['target_user_name' => 'string', 'location_id' => 'integer'], ['id' => 'integer'], AgentAccessManagementService::class),
            new AgentToolDefinition('agent.access.default_location.set', 'users.manage', false, true, true, ['target_user_name' => 'string', 'location_id' => 'integer'], ['id' => 'integer'], AgentAccessManagementService::class),
            new AgentToolDefinition('stock.positions.list', 'stock.view', true, false, false, ['location_id' => 'integer'], ['items' => 'array'], StockPositionService::class),
            new AgentToolDefinition('ingredient_stock.positions.list', 'ingredient_stock.view', true, false, false, ['location_id' => 'integer'], ['items' => 'array'], IngredientStockPositionService::class),
            new AgentToolDefinition('ingredient_stock.shortages.list', 'ingredient_stock.view', true, false, false, ['location_id' => 'integer'], ['items' => 'array'], IngredientShortageService::class),
            new AgentToolDefinition('production.today', 'production.view', true, false, false, ['location_id' => 'integer', 'date' => 'date'], ['items' => 'array'], ProductionQueryService::class),
            new AgentToolDefinition('production.suggestions.list', 'production_requirements.view', true, false, false, ['location_id' => 'integer'], ['items' => 'array'], ProductionRequirementService::class),
            new AgentToolDefinition('production.plan', 'production.create', true, true, true, ['product_id' => 'integer', 'location_id' => 'integer', 'planned_quantity' => 'decimal', 'operation_date' => 'date', 'idempotency_key' => 'string'], ['id' => 'integer'], ProductionService::class),
            new AgentToolDefinition('production.orders.plan', 'production.orders.create', true, true, true, ['location_id' => 'integer', 'production_date' => 'date', 'items' => 'array', 'idempotency_key' => 'string'], ['id' => 'integer'], ProductionOrderService::class),
            new AgentToolDefinition('production.orders.complete_batch', 'production.orders.complete', true, true, true, ['location_id' => 'integer', 'production_date' => 'date', 'items' => 'array', 'idempotency_key' => 'string'], ['id' => 'integer'], ProductionOrderService::class),
            new AgentToolDefinition('production.complete', 'production.create', false, true, true, ['production_id' => 'integer', 'actual_quantity' => 'decimal'], ['id' => 'integer'], ProductionService::class),
            new AgentToolDefinition('losses.record', 'losses.create', true, true, true, ['product_id' => 'integer', 'location_id' => 'integer', 'loss_reason_id' => 'integer', 'quantity' => 'decimal', 'operation_date' => 'date', 'idempotency_key' => 'string'], ['id' => 'integer'], ProductLossService::class),
            new AgentToolDefinition('transfers.list', 'transfers.view', true, false, false, ['location_id' => 'integer', 'status' => 'string'], ['items' => 'array'], StockTransferQueryService::class),
            new AgentToolDefinition('transfers.create', 'transfers.create', false, true, true, ['source_location_id' => 'integer', 'destination_location_id' => 'integer', 'product_id' => 'integer', 'quantity' => 'decimal', 'operation_date' => 'date', 'idempotency_key' => 'string'], ['id' => 'integer'], StockTransferService::class),
            new AgentToolDefinition('transfers.dispatch', 'transfers.create', false, true, true, ['transfer_id' => 'integer', 'dispatch_date' => 'date'], ['id' => 'integer'], StockTransferService::class),
            new AgentToolDefinition('transfers.receive', 'transfers.receive', false, true, true, ['transfer_id' => 'integer', 'received_date' => 'date', 'quantity_received' => 'decimal'], ['id' => 'integer'], StockTransferService::class),
            new AgentToolDefinition('reports.operational.summary', 'reports.view', true, false, false, ['location_id' => 'integer', 'period' => 'string'], ['summary' => 'object'], OperationalSummaryService::class),
            new AgentToolDefinition('finance.payables.list', 'finance.payables.view', true, false, false, ['period' => 'string', 'location_id' => 'integer|null', 'supplier' => 'string|null'], ['items' => 'array'], FinanceQueryService::class),
            new AgentToolDefinition('finance.payables.get', 'finance.payables.view', true, false, false, ['id' => 'integer'], ['payable' => 'object'], FinanceQueryService::class),
            new AgentToolDefinition('finance.payables.create', 'finance.payables.create', true, true, true, ['description' => 'string', 'supplier_name' => 'string|null', 'location_id' => 'integer', 'expected_amount' => 'decimal', 'competency_date' => 'date', 'due_date' => 'date', 'recurring' => 'boolean|null', 'notes' => 'string|null'], ['id' => 'integer'], CreatePayableService::class),
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
