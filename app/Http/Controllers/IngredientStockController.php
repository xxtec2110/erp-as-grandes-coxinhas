<?php

namespace App\Http\Controllers;

use App\Http\Requests\IngredientStockOperationRequest;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\IngredientStockMovement;
use App\Services\AuthorizationService;
use App\Services\IngredientStockOperationService;
use App\Services\IngredientStockPositionService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class IngredientStockController extends Controller
{
    public function index(Request $r, AuthorizationService $a, IngredientStockPositionService $s): View
    {
        $locations = $a->accessibleLocations($r->user());
        $location = $locations->firstWhere('id', $r->integer('location_id')) ?? $locations->firstWhere('id', $r->user()->default_location_id) ?? $locations->first();

        return view('ingredient-stock.index', ['locations' => $locations, 'location' => $location, 'rows' => $location ? $s->forLocation($location, $r->only('ingredient', 'category_id')) : [], 'categories' => IngredientCategory::query()->orderBy('name')->get(), 'ingredients' => Ingredient::query()->where('active', true)->orderBy('name')->get(), 'key' => (string) Str::uuid()]);
    }

    public function show(Ingredient $ingredient, Request $r, AuthorizationService $a): View
    {
        $location = $a->accessibleLocations($r->user())->firstWhere('id', $r->integer('location_id'));
        abort_if(! $location, 403);

        return view('ingredient-stock.show', ['ingredient' => $ingredient, 'location' => $location, 'movements' => IngredientStockMovement::query()->whereBelongsTo($ingredient)->whereBelongsTo($location)->latest('operation_date')->latest('id')->paginate(30)]);
    }

    public function operation(IngredientStockOperationRequest $r, IngredientStockOperationService $s, string $kind): RedirectResponse
    {
        try {
            $s->record($r->validated(), $r->user(), $kind);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }

        return back()->with('success', 'Movimento de insumo registrado.');
    }

    public function loss(IngredientStockOperationRequest $r, IngredientStockOperationService $s): RedirectResponse
    {
        return $this->operation($r, $s, 'loss');
    }

    public function adjustment(IngredientStockOperationRequest $r, IngredientStockOperationService $s): RedirectResponse
    {
        return $this->operation($r, $s, 'adjustment');
    }
}
