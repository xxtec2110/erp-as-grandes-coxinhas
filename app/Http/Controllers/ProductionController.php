<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteProductionRequest;
use App\Http\Requests\ProductionRequest;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductionRecord;
use App\Services\AuthorizationService;
use App\Services\ProductionService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductionController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): View
    {
        $locations = $authorization->accessibleLocations($request->user());

        return view('production.index', [
            'productions' => ProductionRecord::query()
                ->with(['product', 'location'])
                ->whereIn('location_id', $locations->pluck('id'))
                ->orderByDesc('operation_date')
                ->orderByDesc('id')
                ->paginate(20),
        ]);
    }

    public function create(Request $request, AuthorizationService $authorization): View
    {
        return view('production.create', [
            'products' => Product::query()->where('active', true)->orderBy('name')->get(),
            'locations' => $authorization->accessibleLocations($request->user())->where('type', Location::TYPE_PRODUCTION),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(ProductionRequest $request, ProductionService $service, AuthorizationService $authorization): RedirectResponse
    {
        $authorization->authorize($request->user(), 'production.create', (int) $request->validated('location_id'));
        $production = $service->plan($request->validated(), $request->user()?->getKey());

        return redirect()->route('production.show', $production)
            ->with('success', 'Produção planejada com sucesso.');
    }

    public function show(ProductionRecord $production, Request $request, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user(), 'production.view', $production->location_id);

        return view('production.show', [
            'production' => $production->load(['product', 'location', 'creator', 'completer']),
        ]);
    }

    public function complete(
        CompleteProductionRequest $request,
        ProductionRecord $production,
        ProductionService $service,
        AuthorizationService $authorization,
    ): RedirectResponse {
        $authorization->authorize($request->user(), 'production.create', $production->location_id);
        try {
            $service->complete($production, $request->validated('actual_quantity'), $request->user()?->getKey());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['actual_quantity' => $exception->getMessage()]);
        }

        return redirect()->route('production.show', $production)
            ->with('success', 'Produção concluída e estoque atualizado.');
    }

    public function cancel(ProductionRecord $production, ProductionService $service, Request $request, AuthorizationService $authorization): RedirectResponse
    {
        $authorization->authorize($request->user(), 'production.create', $production->location_id);
        try {
            $service->cancel($production);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['production' => $exception->getMessage()]);
        }

        return redirect()->route('production.show', $production)
            ->with('success', 'Produção planejada cancelada.');
    }
}
