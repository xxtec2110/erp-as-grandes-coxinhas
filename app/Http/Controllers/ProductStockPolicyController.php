<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductStockPolicyRequest;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStockPolicy;
use App\Services\ProductStockPolicyService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductStockPolicyController extends Controller
{
    public function index(): View
    {
        return view('stock-policies.index', [
            'policies' => ProductStockPolicy::query()->with(['product', 'location'])->orderBy('location_id')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return $this->formView();
    }

    public function store(ProductStockPolicyRequest $request, ProductStockPolicyService $service): RedirectResponse
    {
        return $this->save($request, $service);
    }

    public function edit(ProductStockPolicy $stockPolicy): View
    {
        $stockPolicy->load(['histories.changer', 'product', 'location']);

        return $this->formView($stockPolicy);
    }

    public function update(
        ProductStockPolicyRequest $request,
        ProductStockPolicy $stockPolicy,
        ProductStockPolicyService $service,
    ): RedirectResponse {
        return $this->save($request, $service);
    }

    private function formView(?ProductStockPolicy $policy = null): View
    {
        return view('stock-policies.form', [
            'policy' => $policy,
            'products' => Product::query()->where('active', true)->orderBy('name')->get(),
            'locations' => Location::query()->where('active', true)->orderBy('name')->get(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    private function save(ProductStockPolicyRequest $request, ProductStockPolicyService $service): RedirectResponse
    {
        try {
            $policy = $service->save($request->validated(), $request->user()?->getKey());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['target_quantity' => $exception->getMessage()]);
        }

        return redirect()->route('stock-policies.edit', $policy)->with('success', 'Política de estoque salva.');
    }
}
