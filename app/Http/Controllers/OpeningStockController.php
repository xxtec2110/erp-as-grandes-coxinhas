<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmOpeningStockRequest;
use App\Http\Requests\OpeningStockRequest;
use App\Models\Product;
use App\Services\AuthorizationService;
use App\Services\OpeningStockService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OpeningStockController extends Controller
{
    public function create(Request $request, AuthorizationService $authorization): View
    {
        return view('stock.opening', [
            'products' => Product::query()->where('active', true)->orderBy('name')->get(),
            'locations' => $authorization->accessibleLocations($request->user()),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function preview(OpeningStockRequest $request, OpeningStockService $service): View
    {
        try {
            $preview = $service->preview($request->validated(), $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['opening_stock' => $exception->getMessage()]);
        }

        return view('stock.opening-preview', compact('preview'));
    }

    public function store(ConfirmOpeningStockRequest $request, OpeningStockService $service): RedirectResponse
    {
        try {
            $movement = $service->confirm($request->validated('preview_token'), $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['opening_stock' => $exception->getMessage()]);
        }

        return redirect()->route('stock.show', [$movement->product_id, $movement->location_id])
            ->with('success', 'Estoque inicial confirmado e registrado no histórico oficial.');
    }
}
