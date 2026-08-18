<?php

namespace App\Agent;

use App\Models\FinancialAccount;
use App\Models\Location;
use App\Models\Payable;
use App\Models\ProductionRecord;
use App\Models\PurchaseDocument;
use App\Models\PurchaseDocumentItem;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\AgentAccessManagementService;
use App\Services\AuthorizationService;
use App\Services\CatalogAgentToolService;
use App\Services\CostQueryService;
use App\Services\CreatePayableService;
use App\Services\CreatePurchaseDocumentService;
use App\Services\DashboardUserVisibilityService;
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
use App\Services\PurchaseReceiptService;
use App\Services\RegisterPaymentService;
use App\Services\StockPositionService;
use App\Services\StockTransferQueryService;
use App\Services\StockTransferService;
use App\Services\UndoLastOperationService;
use DomainException;

class AgentToolExecutor
{
    public function __construct(private AgentToolRegistry $registry, private AuthorizationService $authorization, private CatalogAgentToolService $catalog, private AgentAccessManagementService $accessManagement, private DashboardUserVisibilityService $dashboardVisibility, private FinanceQueryService $finance, private PurchaseQueryService $purchases, private FinanceReportService $reports, private CreatePayableService $createPayable, private RegisterPaymentService $registerPayment, private CreatePurchaseDocumentService $createDocument, private PurchaseDocumentActionService $purchaseActions, private PurchaseReceiptService $purchaseReceipts, private CostQueryService $costs, private StockPositionService $stockPositions, private IngredientStockPositionService $ingredientStockPositions, private IngredientShortageService $ingredientShortages, private ProductionQueryService $productionQuery, private ProductionRequirementService $productionRequirements, private ProductionService $production, private ProductionOrderService $productionOrders, private ProductLossService $losses, private StockTransferQueryService $transfers, private StockTransferService $transferOperations, private OperationalSummaryService $operationalSummary, private UndoLastOperationService $undo) {}

    public function execute(string $name, array $input, User $user, bool $confirmed = false, array $context = []): mixed
    {
        $tool = $this->registry->get($name) ?? throw new DomainException('Ferramenta não registrada.');
        $location = $input['location_id'] ?? null;
        $this->authorization->authorize($user, $tool->permission, $tool->locationScoped && $location !== null ? (int) $location : null);
        if ($tool->writesData && $tool->confirmationRequired && ! $confirmed) {
            throw new DomainException('Esta operação exige confirmação.');
        }

        if ($name === 'production.complete') {
            $this->authorization->authorize($user, $tool->permission, ProductionRecord::query()->findOrFail($input['production_id'])->location_id);
        }
        if (str_starts_with($name, 'transfers.') && $name !== 'transfers.list') {
            $transfer = isset($input['transfer_id']) ? StockTransfer::query()->findOrFail($input['transfer_id']) : null;
            $locationIds = $transfer ? [$transfer->source_location_id, $transfer->destination_location_id] : [$input['source_location_id'], $input['destination_location_id']];
            foreach ($locationIds as $locationId) {
                $this->authorization->authorize($user, $tool->permission, $locationId);
            }
        }

        return match ($name) {
            'catalog.products.create', 'catalog.products.update', 'catalog.products.update_price', 'catalog.product_aliases.create',
            'catalog.suppliers.create', 'catalog.suppliers.update', 'catalog.ingredients.create', 'catalog.ingredients.update',
            'catalog.ingredient_prices.add', 'catalog.preparations.create', 'catalog.preparations.update',
            'catalog.product_recipes.create', 'catalog.product_recipes.update' => $this->catalog->execute($name, $input, $user, $context['channel'] ?? 'agent'),
            'agent.access.permission.grant' => $this->accessManagement->permission($input, $user, true),
            'agent.access.permission.revoke' => $this->accessManagement->permission($input, $user, false),
            'agent.access.location.grant' => $this->accessManagement->location($input, $user, true),
            'agent.access.location.revoke' => $this->accessManagement->location($input, $user, false),
            'agent.access.default_location.set' => $this->accessManagement->defaultLocation($input, $user),
            'dashboard.user_widgets.list' => $this->dashboardVisibility->inspect(User::query()->findOrFail($input['target_user_id']), $user),
            'dashboard.user_widgets.update' => $this->dashboardVisibility->updateFromAgent($input, $user, [...$context, 'tool' => $name]),
            'dashboard.user_widgets.reset' => $this->dashboardVisibility->reset(User::query()->findOrFail($input['target_user_id']), $user, 'agent', [...$context, 'tool' => $name], $input['idempotency_key'] ?? null),
            'stock.positions.list' => $this->stockPositions->forLocation(Location::query()->findOrFail($input['location_id'])),
            'ingredient_stock.positions.list' => $this->ingredientStockPositions->forLocation(Location::query()->findOrFail($input['location_id'])),
            'ingredient_stock.shortages.list' => $this->ingredientShortages->forLocation(Location::query()->findOrFail($input['location_id'])),
            'production.today' => $this->productionQuery->forDate(Location::query()->findOrFail($input['location_id']), $input['date'] ?? now()->toDateString()),
            'production.suggestions.list' => $this->productionRequirements->forLocation(Location::query()->findOrFail($input['location_id'])),
            'production.plan' => $this->production->plan($input, $user->id),
            'production.orders.plan' => $this->productionOrders->plan($input, $user, 'agent'),
            'production.orders.complete_batch' => $this->productionOrders->planAndComplete($input, $user, 'agent'),
            'production.complete' => $this->production->complete(ProductionRecord::query()->findOrFail($input['production_id']), (string) $input['actual_quantity'], $user->id),
            'losses.record' => $this->losses->record($input, $user->id),
            'transfers.list' => $this->transfers->list($user, (int) $input['location_id'], $input['status'] ?? 'recent'),
            'transfers.create' => $this->transferOperations->create($input, $user->id),
            'transfers.dispatch' => $this->transferOperations->dispatch(StockTransfer::query()->findOrFail($input['transfer_id']), $input['dispatch_date'], $user->id),
            'transfers.receive' => $this->receiveTransfer($input, $user),
            'reports.operational.summary' => $this->operationalSummary->summarize(Location::query()->findOrFail($input['location_id']), ...$this->period($input)),
            'finance.payables.list' => $this->finance->payables($user, $input),
            'finance.payables.get' => $this->finance->payable($user, (int) $input['id']),
            'finance.payables.create' => $this->createPayable->create($input, $user, 'agent'),
            'finance.payments.record' => $this->registerPayment->register(Payable::query()->findOrFail($input['payable_id']), collect($input)->except('payable_id')->all(), $user, 'agent'),
            'finance.payments.list' => $this->finance->payments($user, $input),
            'finance.accounts.list' => FinancialAccount::query()->where('active', true)->where(fn ($q) => $q->whereNull('location_id')->orWhereIn('location_id', $this->authorization->accessibleLocations($user)->pluck('id')))->get(),
            'finance.reports.summary' => $this->reports->summary($this->authorization->accessibleLocations($user)->pluck('id')->all(), ...$this->period($input)),
            'purchases.documents.list' => $this->purchases->documents($user, isset($input['location_id']) ? (int) $input['location_id'] : null),
            'purchases.documents.get' => $this->purchases->document($user, (int) $input['id']),
            'purchases.documents.create' => $this->createPurchase($input, $user),
            'purchases.items.list' => $this->purchases->items($user, (int) $input['document_id']),
            'purchases.history' => $this->purchases->history($user, $input),
            'costs.ingredients.current' => $this->costs->ingredientCurrent($user, (int) $input['ingredient_id']),
            'costs.ingredients.history' => $this->costs->ingredientHistory($user, (int) $input['ingredient_id']),
            'costs.ingredients.compare_suppliers' => $this->costs->compareSuppliers($user, (int) $input['ingredient_id']),
            'costs.products.current' => $this->costs->productCurrent($user, (int) $input['product_id']),
            'costs.products.history' => $this->costs->productHistory($user, (int) $input['product_id']),
            'costs.products.margin' => $this->costs->productMargin($user, (int) $input['product_id']),
            'costs.products.margin_history' => $this->costs->productMargin($user, (int) $input['product_id'], $input['date']),
            'purchases.link_supplier' => $this->purchaseActions->linkSupplier(PurchaseDocument::query()->findOrFail($input['document_id']), (int) $input['supplier_id'], $user),
            'purchases.suggest_ingredient_price_update' => $this->purchaseActions->ingredientPriceSuggestion(PurchaseDocumentItem::query()->findOrFail($input['item_id']), $user),
            'agent.operations.undo' => $this->undo->undo($input, $user),
            default => throw new DomainException('Ferramenta sem executor oficial.'),
        };
    }

    private function receiveTransfer(array $input, User $user): StockTransfer
    {
        $transfer = StockTransfer::query()->with('items')->findOrFail($input['transfer_id']);
        if ($transfer->items->count() !== 1) {
            throw new DomainException('Informe as quantidades de cada item desta transferência pela interface web.');
        }

        return $this->transferOperations->receive($transfer, $input['received_date'], [$transfer->items->sole()->id => (string) $input['quantity_received']], $user->id);
    }

    private function createPurchase(array $input, User $user): PurchaseDocument
    {
        $received = filter_var($input['received'] ?? false, FILTER_VALIDATE_BOOL);
        $receivedDate = $input['received_date'] ?? $input['issue_date'] ?? now()->toDateString();
        $document = $this->createDocument->create($input, $user, 'agent');
        if ($received && $document->source_type !== 'quote') {
            return $this->purchaseReceipts->receive($document, $receivedDate, $user, 'agent');
        }

        return $document;
    }

    /** @return array{string,string} */
    private function period(array $input): array
    {
        if (isset($input['start'], $input['end'])) {
            return [$input['start'], $input['end']];
        }

        return match ($input['period'] ?? 'month') {
            'today' => [now()->toDateString(), now()->toDateString()],
            'week' => [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()],
            'fortnight' => [now()->subDays(14)->toDateString(), now()->toDateString()],
            default => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
        };
    }
}
