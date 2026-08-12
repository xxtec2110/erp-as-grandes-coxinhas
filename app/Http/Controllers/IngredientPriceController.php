<?php

namespace App\Http\Controllers;

use App\Http\Requests\IngredientPriceRequest;
use App\Models\Ingredient;
use App\Services\IngredientPriceService;
use Illuminate\Http\RedirectResponse;

class IngredientPriceController extends Controller
{
    public function store(
        IngredientPriceRequest $request,
        Ingredient $ingredient,
        IngredientPriceService $priceService,
    ): RedirectResponse {
        $priceService->record($ingredient, $request->validated());

        return redirect()->route('ingredients.show', $ingredient)
            ->with('success', 'Preço registrado e custo normalizado calculado com sucesso.');
    }
}
