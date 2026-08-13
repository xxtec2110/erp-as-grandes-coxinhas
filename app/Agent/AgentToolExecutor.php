<?php

namespace App\Agent;

use App\Models\FinancialAccount;
use App\Models\Location;
use App\Models\Payable;
use App\Models\PurchaseDocument;
use App\Models\PurchaseDocumentItem;
use App\Models\User;
use App\Services\AuthorizationService;
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
use DomainException;

class AgentToolExecutor
{
    public function __construct(private AgentToolRegistry $registry, private AuthorizationService $authorization, private FinanceQueryService $finance, private PurchaseQueryService $purchases, private FinanceReportService $reports, private CreatePayableService $createPayable, private RegisterPaymentService $registerPayment, private CreatePurchaseDocumentService $createDocument, private PurchaseDocumentActionService $purchaseActions, private StockPositionService $stockPositions, private ProductionQueryService $productionQuery, private ProductionRequirementService $productionRequirements, private ProductionService $production, private StockTransferQueryService $transfers, private OperationalSummaryService $operationalSummary, private UndoLastOperationService $undo) {}

    public function execute(string $name, array $input, User $user, bool $confirmed = false): mixed
    {
        $tool = $this->registry->get($name) ?? throw new DomainException('Ferramenta não registrada.');
        $location = $input['location_id'] ?? null;
        $this->authorization->authorize($user, $tool->permission, $tool->locationScoped && $location !== null ? (int) $location : null);
        if ($tool->writesData && $tool->confirmationRequired && ! $confirmed) {
            throw new DomainException('Esta operação exige confirmação.');
        }

        return match ($name) {
            'stock.positions.list' => $this->stockPositions->forLocation(Location::query()->findOrFail($input['location_id'])),
            'production.today' => $this->productionQuery->forDate(Location::query()->findOrFail($input['location_id']), $input['date'] ?? now()->toDateString()),
            'production.suggestions.list' => $this->productionRequirements->forLocation(Location::query()->findOrFail($input['location_id'])),
            'production.plan' => $this->production->plan($input, $user->id),
            'transfers.list' => $this->transfers->list($user, (int) $input['location_id'], $input['status'] ?? 'recent'),
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
            'purchases.documents.create' => $this->createDocument->create($input, $user, 'agent'),
            'purchases.items.list' => $this->purchases->items($user, (int) $input['document_id']),
            'purchases.link_supplier' => $this->purchaseActions->linkSupplier(PurchaseDocument::query()->findOrFail($input['document_id']), (int) $input['supplier_id'], $user),
            'purchases.suggest_ingredient_price_update' => $this->purchaseActions->ingredientPriceSuggestion(PurchaseDocumentItem::query()->findOrFail($input['item_id']), $user),
            'agent.operations.undo' => $this->undo->undo($input, $user),
            default => throw new DomainException('Ferramenta sem executor oficial.'),
        };
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
            default => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
        };
    }
}
