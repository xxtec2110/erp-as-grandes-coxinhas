<?php

namespace App\Http\Controllers;

use App\Http\Requests\IngredientCategoryRequest;
use App\Models\IngredientCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class IngredientCategoryController extends Controller
{
    public function index(): View
    {
        return view('ingredient-categories.index', [
            'categories' => IngredientCategory::query()
                ->withCount('ingredients')
                ->orderBy('name')
                ->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('ingredient-categories.create');
    }

    public function store(IngredientCategoryRequest $request): RedirectResponse
    {
        IngredientCategory::query()->create($request->validated());

        return redirect()->route('ingredient-categories.index')
            ->with('success', 'Categoria de insumo cadastrada com sucesso.');
    }

    public function edit(IngredientCategory $ingredientCategory): View
    {
        return view('ingredient-categories.edit', compact('ingredientCategory'));
    }

    public function update(
        IngredientCategoryRequest $request,
        IngredientCategory $ingredientCategory,
    ): RedirectResponse {
        $ingredientCategory->update($request->validated());

        return redirect()->route('ingredient-categories.index')
            ->with('success', 'Categoria de insumo atualizada com sucesso.');
    }
}
