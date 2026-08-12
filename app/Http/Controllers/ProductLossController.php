<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductLossRequest;
use App\Models\LossReason;
use App\Models\Product;
use App\Models\ProductLoss;
use App\Services\AuthorizationService;
use App\Services\ProductLossService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductLossController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): View
    {
        $locations = $authorization->accessibleLocations($request->user());

        return view('losses.index', [
            'losses' => ProductLoss::query()->with(['product', 'location', 'reason', 'creator'])->whereIn('location_id', $locations->pluck('id'))->latest('operation_date')->latest('id')->paginate(20),
        ]);
    }

    public function create(Request $request, AuthorizationService $authorization): View
    {
        return view('losses.create', [
            'products' => Product::query()->where('active', true)->orderBy('name')->get(),
            'locations' => $authorization->accessibleLocations($request->user()),
            'reasons' => LossReason::query()->where('active', true)->orderBy('name')->get(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(ProductLossRequest $request, ProductLossService $service, AuthorizationService $authorization): RedirectResponse
    {
        $authorization->authorize($request->user(), 'losses.create', (int) $request->validated('location_id'));
        try {
            $service->record($request->validated(), $request->user()?->getKey());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
        }

        return redirect()->route('losses.index')->with('success', 'Perda registrada e estoque atualizado.');
    }
}
