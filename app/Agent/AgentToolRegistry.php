<?php

namespace App\Agent;

use App\Services\AgentAccessManagementService;
use App\Services\CatalogAgentToolService;
use App\Services\CostQueryService;
use App\Services\CreatePayableService;
use App\Services\CreatePurchaseDocumentService;
use App\Services\DashboardUserVisibilityService;
use App\Services\FinanceQueryService;
use App\Services\FinanceReportService;
use App\Services\IngredientShortageService;
use App\Services\IngredientStockPositionService;
use App\Services\OpeningStockService;
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
            new AgentToolDefinition('catalog.products.create', 'products.create', false, true, true, ['name' => 'string', 'selling_price' => 'decimal', 'product_category_id' => 'integer|null', 'stock_unit' => 'string|null', 'sort_order' => 'integer|null', 'active' => 'boolean|null'], ['id' => 'integer'], CatalogAgentToolService::class),
            new AgentToolDefinition('catalog.products.update', 'products.update', false, true, true, ['product_id' => 'integer'], ['id' => 'integer'], CatalogAgentToolService::class),
            new AgentToolDefinition('catalog.products.update_price', 'prices.manage', false, true, true, ['product_id' => 'integer', 'selling_price' => 'decimal'], ['id' => 'integer'], CatalogAgentToolService::class),
            new AgentToolDefinition('catalog.product_aliases.create', 'products.update', false, true, true, ['product_id' => 'integer', 'alias' => 'string'], ['id' => 'integer'], CatalogAgentToolService::class),
            new AgentToolDefinition('catalog.suppliers.create', 'suppliers.manage', false, true, true, ['name' => 'string'], ['id' => 'integer'], CatalogAgentToolService::class),
            new AgentToolDefinition('catalog.suppliers.update', 'suppliers.manage', false, true, true, ['supplier_id' => 'integer'], ['id' => 'integer'], CatalogAgentToolService::class),
            new AgentToolDefinition('catalog.ingredients.create', 'ingredients.create', false, true, true, ['name' => 'string', 'base_unit' => 'string'], ['id' => 'integer'], CatalogAgentToolService::class),
            new AgentToolDefinition('catalog.ingredients.update', 'ingredients.update', false, true, true, ['ingredient_id' => 'integer'], ['id' => 'integer'], CatalogAgentToolService::class),
            new AgentToolDefinition('catalog.ingredient_prices.add', 'ingredient_prices.update', false, true, true, ['ingredient_id' => 'integer', 'supplier_id' => 'integer', 'purchase_quantity' => 'decimal', 'purchase_unit' => 'string', 'price_paid' => 'decimal', 'effective_date' => 'date'], ['id' => 'integer'], CatalogAgentToolService::class),
            new AgentToolDefinition('catalog.preparations.create', 'preparations.create', false, true, true, ['name' => 'string', 'expected_yield' => 'decimal', 'yield_unit' => 'string', 'total_preparation_time_minutes' => 'integer'], ['id' => 'integer'], CatalogAgentToolService::class),
            new AgentToolDefinition('catalog.preparations.update', 'preparations.update', false, true, true, ['preparation_id' => 'integer'], ['id' => 'integer'], CatalogAgentToolService::class),
            new AgentToolDefinition('catalog.product_recipes.create', 'product_recipes.manage', false, true, true, ['product_id' => 'integer', 'yield_quantity' => 'decimal', 'technical_loss_percentage' => 'decimal', 'packaging_cost' => 'decimal'], ['id' => 'integer'], CatalogAgentToolService::class),
            new AgentToolDefinition('catalog.product_recipes.update', 'product_recipes.manage', false, true, true, ['product_id' => 'integer', 'yield_quantity' => 'decimal', 'technical_loss_percentage' => 'decimal', 'packaging_cost' => 'decimal'], ['id' => 'integer'], CatalogAgentToolService::class),
            new AgentToolDefinition('agent.access.permission.grant', 'user_permissions.manage', false, true, true, ['target_user_name' => 'string', 'permission' => 'string'], ['id' => 'integer'], AgentAccessManagementService::class),
            new AgentToolDefinition('agent.access.permission.revoke', 'user_permissions.manage', false, true, true, ['target_user_name' => 'string', 'permission' => 'string'], ['id' => 'integer'], AgentAccessManagementService::class),
            new AgentToolDefinition('agent.access.locations.list', 'user_locations.manage', false, false, false, ['target_user_name' => 'string'], ['locations' => 'array'], AgentAccessManagementService::class),
            new AgentToolDefinition('agent.access.locations.replace', 'user_locations.manage', false, true, true, ['target_user_name' => 'string', 'location_ids' => 'array'], ['id' => 'integer'], AgentAccessManagementService::class),
            new AgentToolDefinition('agent.access.location.grant', 'user_locations.manage', false, true, true, ['target_user_name' => 'string', 'location_id' => 'integer'], ['id' => 'integer'], AgentAccessManagementService::class),
            new AgentToolDefinition('agent.access.location.revoke', 'user_locations.manage', false, true, true, ['target_user_name' => 'string', 'location_id' => 'integer'], ['id' => 'integer'], AgentAccessManagementService::class),
            new AgentToolDefinition('agent.access.default_location.set', 'user_locations.manage', false, true, true, ['target_user_name' => 'string', 'location_id' => 'integer'], ['id' => 'integer'], AgentAccessManagementService::class),
            new AgentToolDefinition('dashboard.user_widgets.list', 'dashboard.permissions.manage', false, false, false, ['target_user_id' => 'integer|null', 'target_user_name' => 'string|null'], ['widgets' => 'array'], DashboardUserVisibilityService::class),
            new AgentToolDefinition('dashboard.user_widgets.update', 'dashboard.permissions.manage', false, true, true, ['target_user_id' => 'integer|null', 'target_user_name' => 'string|null', 'show' => 'array', 'hide' => 'array', 'mode' => 'string|null'], ['id' => 'integer'], DashboardUserVisibilityService::class),
            new AgentToolDefinition('dashboard.user_widgets.reset', 'dashboard.permissions.manage', false, true, true, ['target_user_id' => 'integer|null', 'target_user_name' => 'string|null'], ['id' => 'integer'], DashboardUserVisibilityService::class),
            new AgentToolDefinition('stock.positions.list', 'stock.view', true, false, false, ['location_id' => 'integer'], ['items' => 'array'], StockPositionService::class),
            new AgentToolDefinition('stock.opening_balance.record', 'stock.opening_balance', true, true, true, ['product_id' => 'integer', 'location_id' => 'integer', 'quantity' => 'decimal', 'operation_date' => 'date', 'notes' => 'string', 'idempotency_key' => 'string'], ['id' => 'integer'], OpeningStockService::class),
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
            new AgentToolDefinition('transfers.complete', 'transfers.create', false, true, true, ['source_location_id' => 'integer', 'destination_location_id' => 'integer', 'product_id' => 'integer', 'quantity' => 'decimal', 'operation_date' => 'date', 'idempotency_key' => 'string'], ['id' => 'integer'], StockTransferService::class),
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
            new AgentToolDefinition('purchases.documents.create', 'purchases.create', true, true, true, ['location_id' => 'integer', 'supplier_id' => 'integer', 'document_type' => 'string', 'document_number' => 'string|null', 'series' => 'string|null', 'access_key' => 'string|null', 'issue_date' => 'date', 'currency' => 'string', 'gross_amount' => 'decimal|null', 'discount_amount' => 'decimal|null', 'freight_amount' => 'decimal|null', 'other_charges_amount' => 'decimal|null', 'total_amount' => 'decimal', 'items' => 'array', 'received' => 'boolean', 'received_date' => 'date|null'], ['id' => 'integer'], CreatePurchaseDocumentService::class),
            new AgentToolDefinition('purchases.documents.list', 'purchases.view', true, false, false, [], ['items' => 'array'], PurchaseQueryService::class),
            new AgentToolDefinition('purchases.documents.get', 'purchases.view', true, false, false, ['id' => 'integer'], ['document' => 'object'], PurchaseQueryService::class),
            new AgentToolDefinition('purchases.items.list', 'purchases.view', true, false, false, ['document_id' => 'integer'], ['items' => 'array'], PurchaseQueryService::class),
            new AgentToolDefinition('purchases.history', 'purchases.view', false, false, false, ['location_id' => 'integer|null', 'supplier_id' => 'integer|null', 'ingredient_id' => 'integer|null', 'start_date' => 'date|null', 'end_date' => 'date|null'], ['items' => 'array'], PurchaseQueryService::class),
            new AgentToolDefinition('costs.ingredients.current', 'ingredients.view', false, false, false, ['ingredient_id' => 'integer'], ['cost' => 'object'], CostQueryService::class),
            new AgentToolDefinition('costs.ingredients.history', 'ingredients.view', false, false, false, ['ingredient_id' => 'integer'], ['items' => 'array'], CostQueryService::class),
            new AgentToolDefinition('costs.ingredients.compare_suppliers', 'ingredients.view', false, false, false, ['ingredient_id' => 'integer'], ['items' => 'array'], CostQueryService::class),
            new AgentToolDefinition('costs.products.current', 'products.view', false, false, false, ['product_id' => 'integer'], ['cost' => 'object'], CostQueryService::class),
            new AgentToolDefinition('costs.products.history', 'products.view', false, false, false, ['product_id' => 'integer'], ['items' => 'array'], CostQueryService::class),
            new AgentToolDefinition('costs.products.margin', 'products.view', false, false, false, ['product_id' => 'integer'], ['margin' => 'object'], CostQueryService::class),
            new AgentToolDefinition('costs.products.margin_history', 'products.view', false, false, false, ['product_id' => 'integer', 'date' => 'date'], ['margin' => 'object'], CostQueryService::class),
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
