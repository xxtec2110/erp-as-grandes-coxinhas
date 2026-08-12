<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductionEquipmentRequest;
use App\Models\ProductionEquipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProductionEquipmentController extends Controller
{
    public function index(): View
    {
        return view('equipment.index', [
            'equipment' => ProductionEquipment::query()->withCount('burners')->orderBy('name')->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('equipment.create');
    }

    public function store(ProductionEquipmentRequest $request): RedirectResponse
    {
        $equipment = ProductionEquipment::query()->create($request->validated());

        return redirect()->route('equipment.show', $equipment)
            ->with('success', 'Equipamento cadastrado com sucesso.');
    }

    public function show(ProductionEquipment $equipment): View
    {
        $equipment->load(['burners' => fn ($query) => $query->orderBy('name')]);

        return view('equipment.show', compact('equipment'));
    }

    public function edit(ProductionEquipment $equipment): View
    {
        return view('equipment.edit', compact('equipment'));
    }

    public function update(
        ProductionEquipmentRequest $request,
        ProductionEquipment $equipment,
    ): RedirectResponse {
        $equipment->update($request->validated());

        return redirect()->route('equipment.show', $equipment)
            ->with('success', 'Equipamento atualizado com sucesso.');
    }
}
