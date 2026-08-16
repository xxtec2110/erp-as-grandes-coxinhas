<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreparationRequest;
use App\Models\GlpProduct;
use App\Models\Ingredient;
use App\Models\Preparation;
use App\Models\ProductionEquipment;
use App\Services\PreparationCatalogService;
use App\Services\PreparationCostService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PreparationController extends Controller
{
    public function __construct(private PreparationCatalogService $catalog) {}

    public function index(): View
    {
        $preparations = Preparation::query()
            ->withCount('preparationIngredients')
            ->orderBy('name')
            ->paginate(15);

        return view('preparations.index', compact('preparations'));
    }

    public function create(): View
    {
        return view('preparations.create');
    }

    public function store(PreparationRequest $request): RedirectResponse
    {
        $preparation = $this->catalog->create($request->validated());

        return redirect()->route('preparations.show', $preparation)
            ->with('success', 'Preparação cadastrada com sucesso.');
    }

    public function show(Preparation $preparation, PreparationCostService $costService): View
    {
        $calculation = $costService->calculate($preparation);
        $ingredients = Ingredient::query()->where('active', true)->orderBy('name')->get();
        $equipment = ProductionEquipment::query()
            ->where('active', true)
            ->where('energy_source', 'glp')
            ->with(['burners' => fn ($query) => $query->where('active', true)->orderBy('name')])
            ->orderBy('name')
            ->get();
        $glpProducts = GlpProduct::query()->where('active', true)->orderBy('name')->get();

        return view('preparations.show', compact(
            'preparation',
            'calculation',
            'ingredients',
            'equipment',
            'glpProducts',
        ));
    }

    public function edit(Preparation $preparation): View
    {
        return view('preparations.edit', compact('preparation'));
    }

    public function update(PreparationRequest $request, Preparation $preparation): RedirectResponse
    {
        $this->catalog->update($preparation, $request->validated());

        return redirect()->route('preparations.show', $preparation)
            ->with('success', 'Preparação atualizada com sucesso.');
    }
}
