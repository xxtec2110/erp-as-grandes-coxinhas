<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\AuthorizationService;
use App\Services\StockBalanceService;
use App\Services\StockPositionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockController extends Controller
{
    public function index(Request $request, StockBalanceService $balances, StockPositionService $positions, AuthorizationService $authorization): View
    {
        $products = Product::query()->where('active', true)->orderBy('name')->get();
        $locations = $authorization->accessibleLocations($request->user());
        $selectedProduct = $request->integer('product_id') ? $products->firstWhere('id', $request->integer('product_id')) : null;
        $requestedLocationId = $request->integer('location_id');
        if ($request->has('location_id') && ! $locations->contains('id', $requestedLocationId)) {
            abort(403, 'Você não possui acesso a esta unidade.');
        }
        $selectedLocation = $locations->firstWhere('id', $requestedLocationId)
            ?? $locations->firstWhere('id', $request->user()->default_location_id)
            ?? $locations->first();

        return view('stock.index', [
            'products' => $products,
            'locations' => $locations,
            'selectedProduct' => $selectedProduct,
            'selectedLocation' => $selectedLocation,
            'balance' => $selectedProduct && $selectedLocation ? $balances->balance($selectedProduct, $selectedLocation) : null,
            'recentMovements' => StockMovement::query()->with(['product', 'location'])->when($selectedLocation, fn ($query) => $query->where('location_id', $selectedLocation->id))->latest('id')->limit(20)->get(),
            'stockPositions' => $selectedLocation ? $positions->forLocation($selectedLocation) : [],
        ]);
    }

    public function show(Product $product, Location $location, StockBalanceService $balances, Request $request, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user(), 'stock.view', $location);

        return view('stock.show', [
            'product' => $product,
            'location' => $location,
            'balance' => $balances->balance($product, $location),
            'movements' => StockMovement::query()
                ->with('creator')
                ->whereBelongsTo($product)
                ->whereBelongsTo($location)
                ->orderByDesc('operation_date')
                ->orderByDesc('id')
                ->paginate(20),
        ]);
    }
}
