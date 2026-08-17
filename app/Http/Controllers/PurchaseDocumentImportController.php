<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePurchaseDocumentImportRequest;
use App\Http\Requests\UpdatePurchaseDocumentImportRequest;
use App\Models\Ingredient;
use App\Models\PurchaseDocumentImport;
use App\Models\Supplier;
use App\Services\AuthorizationService;
use App\Services\IngredientPriceAnalyticsService;
use App\Services\PurchaseDocumentImportService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseDocumentImportController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): View
    {
        $locationIds = $authorization->accessibleLocations($request->user())->pluck('id');

        return view('purchases.imports.index', ['imports' => PurchaseDocumentImport::query()->with(['supplier', 'location', 'attachments'])->whereIn('location_id', $locationIds)->latest()->paginate(20)]);
    }

    public function create(Request $request, AuthorizationService $authorization): View
    {
        return view('purchases.imports.create', ['locations' => $authorization->accessibleLocations($request->user())]);
    }

    public function store(StorePurchaseDocumentImportRequest $request, PurchaseDocumentImportService $service): RedirectResponse
    {
        $import = $service->upload($request->file('attachments'), $request->integer('location_id'), $request->user());

        return redirect()->route('purchase-imports.show', $import)->with('success', 'Documento armazenado e preparado para revisão.');
    }

    public function show(PurchaseDocumentImport $import, Request $request, AuthorizationService $authorization, IngredientPriceAnalyticsService $analytics): View
    {
        $authorization->authorize($request->user(), 'purchases.view', $import->location_id);
        abort_unless($import->user_id === $request->user()->id || $request->user()->is_super_admin, 403);
        $import->load(['supplier', 'location', 'attachments', 'items.ingredient.currentPrice.supplier', 'confirmedDocument']);

        return view('purchases.imports.show', [
            'import' => $import,
            'suppliers' => Supplier::query()->where('active', true)->orderBy('name')->get(),
            'ingredients' => Ingredient::query()->where('active', true)->orderBy('name')->get(),
            'priceSummaries' => $import->items->filter(fn ($item) => $item->ingredient !== null)->mapWithKeys(fn ($item) => [$item->id => $analytics->summary($item->ingredient)]),
            'priceComparisons' => $import->items->filter(fn ($item) => $item->ingredient !== null)->mapWithKeys(fn ($item) => [$item->id => $analytics->compareToCurrent($item->ingredient, $item->normalized_unit_cost)]),
        ]);
    }

    public function update(UpdatePurchaseDocumentImportRequest $request, PurchaseDocumentImport $import, PurchaseDocumentImportService $service): RedirectResponse
    {
        try {
            $service->revise($import, $request->validated(), $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['review' => $exception->getMessage()]);
        }

        return back()->with('success', 'Revisão salva. Confira os totais antes de confirmar.');
    }

    public function confirm(PurchaseDocumentImport $import, Request $request, PurchaseDocumentImportService $service): RedirectResponse
    {
        try {
            $document = $service->confirm($import, $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['confirmation' => $exception->getMessage()]);
        }

        return redirect()->route('purchases.show', $document)->with('success', 'Compra confirmada com histórico de preços preservado.');
    }

    public function cancel(PurchaseDocumentImport $import, Request $request, PurchaseDocumentImportService $service): RedirectResponse
    {
        $service->cancel($import, $request->user());

        return redirect()->route('purchase-imports.index')->with('success', 'Revisão cancelada sem alterar compras, preços ou estoque.');
    }
}
