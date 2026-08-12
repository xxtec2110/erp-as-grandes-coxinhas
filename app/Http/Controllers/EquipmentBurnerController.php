<?php

namespace App\Http\Controllers;

use App\Http\Requests\EquipmentBurnerRequest;
use App\Models\EquipmentBurner;
use App\Models\ProductionEquipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class EquipmentBurnerController extends Controller
{
    public function store(
        EquipmentBurnerRequest $request,
        ProductionEquipment $equipment,
    ): RedirectResponse {
        abort_unless($equipment->energy_source === 'glp', 422);

        $equipment->burners()->create($request->validated());

        return redirect()->route('equipment.show', $equipment)
            ->with('success', 'Queimador cadastrado com sucesso.');
    }

    public function edit(
        ProductionEquipment $equipment,
        EquipmentBurner $burner,
    ): View {
        $this->ensureBelongsToEquipment($equipment, $burner);

        return view('equipment.burners.edit', compact('equipment', 'burner'));
    }

    public function update(
        EquipmentBurnerRequest $request,
        ProductionEquipment $equipment,
        EquipmentBurner $burner,
    ): RedirectResponse {
        $this->ensureBelongsToEquipment($equipment, $burner);
        $burner->update($request->validated());

        return redirect()->route('equipment.show', $equipment)
            ->with('success', 'Queimador atualizado com sucesso.');
    }

    private function ensureBelongsToEquipment(
        ProductionEquipment $equipment,
        EquipmentBurner $burner,
    ): void {
        abort_unless($burner->production_equipment_id === $equipment->id, 404);
    }
}
