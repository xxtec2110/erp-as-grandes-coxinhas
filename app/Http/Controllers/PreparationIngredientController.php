<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreparationIngredientRequest;
use App\Models\Preparation;
use App\Models\PreparationIngredient;
use Illuminate\Http\RedirectResponse;

class PreparationIngredientController extends Controller
{
    public function store(PreparationIngredientRequest $request, Preparation $preparation): RedirectResponse
    {
        $preparation->preparationIngredients()->create($request->validated());

        return redirect()->route('preparations.show', $preparation)
            ->with('success', 'Ingrediente adicionado com sucesso.');
    }

    public function destroy(Preparation $preparation, PreparationIngredient $preparationIngredient): RedirectResponse
    {
        abort_unless($preparationIngredient->preparation_id === $preparation->id, 404);
        $preparationIngredient->delete();

        return redirect()->route('preparations.show', $preparation)
            ->with('success', 'Ingrediente removido com sucesso.');
    }
}
