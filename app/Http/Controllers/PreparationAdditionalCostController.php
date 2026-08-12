<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreparationAdditionalCostRequest;
use App\Models\Preparation;
use App\Models\PreparationAdditionalCost;
use Illuminate\Http\RedirectResponse;

class PreparationAdditionalCostController extends Controller
{
    public function store(PreparationAdditionalCostRequest $request, Preparation $preparation): RedirectResponse
    {
        $preparation->additionalCosts()->create($request->validated());

        return redirect()->route('preparations.show', $preparation)
            ->with('success', 'Custo adicional incluído com sucesso.');
    }

    public function destroy(Preparation $preparation, PreparationAdditionalCost $additionalCost): RedirectResponse
    {
        abort_unless($additionalCost->preparation_id === $preparation->id, 404);
        $additionalCost->delete();

        return redirect()->route('preparations.show', $preparation)
            ->with('success', 'Custo adicional removido com sucesso.');
    }
}
