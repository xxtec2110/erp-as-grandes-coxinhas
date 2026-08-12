<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreparationEnergyUsageRequest;
use App\Models\Preparation;
use App\Models\PreparationEnergyUsage;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PreparationEnergyUsageController extends Controller
{
    public function store(PreparationEnergyUsageRequest $request, Preparation $preparation): RedirectResponse
    {
        $preparation->energyUsages()->create($request->validated());

        return redirect()->route('preparations.show', $preparation)
            ->with('success', 'Uso de GLP adicionado com sucesso.');
    }

    public function edit(Preparation $preparation, PreparationEnergyUsage $energyUsage): View
    {
        $this->ensureBelongsToPreparation($preparation, $energyUsage);
        $energyUsage->load(['equipment', 'burner', 'glpProduct']);

        return view('preparations.energy-usages.edit', compact('preparation', 'energyUsage'));
    }

    public function update(
        PreparationEnergyUsageRequest $request,
        Preparation $preparation,
        PreparationEnergyUsage $energyUsage,
    ): RedirectResponse {
        $this->ensureBelongsToPreparation($preparation, $energyUsage);
        $energyUsage->update($request->validated());

        return redirect()->route('preparations.show', $preparation)
            ->with('success', 'Uso de GLP atualizado com sucesso.');
    }

    public function destroy(Preparation $preparation, PreparationEnergyUsage $energyUsage): RedirectResponse
    {
        $this->ensureBelongsToPreparation($preparation, $energyUsage);
        $energyUsage->delete();

        return redirect()->route('preparations.show', $preparation)
            ->with('success', 'Uso de GLP removido com sucesso.');
    }

    private function ensureBelongsToPreparation(
        Preparation $preparation,
        PreparationEnergyUsage $energyUsage,
    ): void {
        abort_unless($energyUsage->preparation_id === $preparation->id, 404);
    }
}
