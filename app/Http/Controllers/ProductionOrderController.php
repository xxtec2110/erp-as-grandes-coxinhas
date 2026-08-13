<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteProductionOrderRequest;
use App\Http\Requests\ProductionOrderRequest;
use App\Http\Requests\ReverseProductionOrderRequest;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Services\AuthorizationService;
use App\Services\ProductionOrderService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductionOrderController extends Controller
{
    public function index(Request $request, AuthorizationService $auth): View
    {
        return view('production-orders.index', ['orders' => ProductionOrder::query()->with(['location', 'items.product'])->whereIn('location_id', $auth->accessibleLocations($request->user())->pluck('id'))->latest('production_date')->paginate(20)]);
    }

    public function create(Request $request, AuthorizationService $auth): View
    {
        return view('production-orders.create', ['locations' => $auth->accessibleLocations($request->user())->where('type', Location::TYPE_PRODUCTION), 'products' => Product::query()->where('active', true)->whereHas('recipe')->orderBy('name')->get(), 'key' => (string) Str::uuid()]);
    }

    public function store(ProductionOrderRequest $request, ProductionOrderService $service): RedirectResponse
    {
        $order = $service->plan($request->validated(), $request->user());

        return redirect()->route('production-orders.show', $order)->with('success', 'Ordem planejada.');
    }

    public function show(ProductionOrder $order, Request $request, AuthorizationService $auth): View
    {
        $auth->authorize($request->user(), 'production.orders.view', $order->location_id);

        return view('production-orders.show', ['order' => $order->load(['location', 'items.product', 'audits'])]);
    }

    public function complete(CompleteProductionOrderRequest $request, ProductionOrder $order, ProductionOrderService $service): RedirectResponse
    {
        try {
            $service->complete($order, $request->validated('quantities'), $request->user());
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        return back()->with('success', 'Ordem concluída, insumos consumidos e produtos adicionados ao estoque.');
    }

    public function reverse(ReverseProductionOrderRequest $request, ProductionOrder $order, ProductionOrderService $service): RedirectResponse
    {
        try {
            $service->reverse($order, $request->validated('reason'), $request->user());
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        return back()->with('success', 'Ordem revertida por movimentos compensatórios.');
    }
}
