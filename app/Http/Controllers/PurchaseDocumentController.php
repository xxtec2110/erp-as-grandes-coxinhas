<?php

namespace App\Http\Controllers;

use App\Http\Requests\PartialPurchaseReceiptRequest;
use App\Http\Requests\PurchaseDocumentRequest;
use App\Http\Requests\PurchaseReceiptRequest;
use App\Models\CostCenter;
use App\Models\FinanceCategory;
use App\Models\Ingredient;
use App\Models\PurchaseDocument;
use App\Models\Supplier;
use App\Services\AuthorizationService;
use App\Services\CreatePurchaseDocumentService;
use App\Services\PurchasePayableService;
use App\Services\PurchaseReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseDocumentController
{
    public function index(Request $r, AuthorizationService $a): View
    {
        $ids = $a->accessibleLocations($r->user())->pluck('id');

        return view('purchases.index', ['documents' => PurchaseDocument::query()->with(['supplier', 'location'])->whereIn('location_id', $ids)->latest('issue_date')->paginate(20)]);
    }

    public function create(Request $r, AuthorizationService $a): View
    {
        return view('purchases.create', ['suppliers' => Supplier::query()->orderBy('name')->get(), 'locations' => $a->accessibleLocations($r->user()), 'categories' => FinanceCategory::query()->where('active', true)->get(), 'centers' => CostCenter::query()->where('active', true)->get(), 'ingredients' => Ingredient::query()->where('active', true)->orderBy('name')->get(), 'key' => (string) Str::uuid()]);
    }

    public function show(PurchaseDocument $document, Request $request, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user(), 'purchases.view', $document->location_id);

        return view('purchases.show', ['document' => $document->load(['supplier', 'location', 'items.ingredient.currentPrice', 'items.priceHistory', 'receipts.items']), 'key' => (string) Str::uuid()]);
    }

    public function store(PurchaseDocumentRequest $r, CreatePurchaseDocumentService $s): RedirectResponse
    {
        $s->create($r->validated(), $r->user());

        return redirect()->route('purchases.index')->with('success', 'Documento cadastrado.');
    }

    public function receive(PurchaseReceiptRequest $request, PurchaseDocument $document, PurchaseReceiptService $service): RedirectResponse
    {
        $service->receive($document, $request->validated('received_date'), $request->user());

        return back()->with('success', 'Mercadoria recebida e estoque de insumos atualizado.');
    }

    public function receivePartial(PartialPurchaseReceiptRequest $request, PurchaseDocument $document, PurchaseReceiptService $service): RedirectResponse
    {
        try {
            $service->receivePartial($document, $request->validated('received_date'), $request->validated('quantities'), $request->validated('idempotency_key'), $request->user());
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['receipt' => $exception->getMessage()]);
        }

        return back()->with('success', 'Recebimento físico registrado.');
    }

    public function payable(PurchaseDocument $document, Request $request, PurchasePayableService $service): RedirectResponse
    {
        try {
            $service->create($document, $request->user());
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['payable' => $exception->getMessage()]);
        }

        return back()->with('success', 'Conta a pagar vinculada ao documento.');
    }
}
