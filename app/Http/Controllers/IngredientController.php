<?php

namespace App\Http\Controllers;

use App\Http\Requests\IngredientRequest;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\IngredientCatalogService;
use App\Services\IngredientPriceAnalyticsService;
use App\Services\UnitConversionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class IngredientController extends Controller
{
    public function __construct(private IngredientCatalogService $catalog) {}

    public function index(): View
    {
        return view('ingredients.index', [
            'ingredients' => Ingredient::query()
                ->with(['category', 'currentPrice.supplier'])
                ->orderBy('name')
                ->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('ingredients.create', [
            'categories' => IngredientCategory::query()->where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(IngredientRequest $request): RedirectResponse
    {
        $ingredient = $this->catalog->create($request->validated());

        return redirect()->route('ingredients.show', $ingredient)
            ->with('success', 'Insumo cadastrado. Agora você pode adicionar o primeiro preço.');
    }

    public function show(Ingredient $ingredient, UnitConversionService $conversion, IngredientPriceAnalyticsService $analytics): View
    {
        $ingredient->load([
            'category',
            'currentPrice.supplier',
            'prices' => fn ($query) => $query->with(['supplier', 'purchaseDocument'])->latest('effective_date')->latest('id'),
        ]);

        return view('ingredients.show', [
            'ingredient' => $ingredient,
            'suppliers' => Supplier::query()->where('active', true)->orderBy('name')->get(),
            'purchaseUnits' => $conversion->allowedPurchaseUnits($ingredient->base_unit),
            'conversion' => $conversion,
            'priceSummary' => $analytics->summary($ingredient),
            'supplierComparison' => $analytics->suppliers($ingredient),
            'impactedProducts' => Product::query()->where(fn ($query) => $query
                ->whereHas('recipe.ingredients', fn ($items) => $items->where('ingredient_id', $ingredient->id))
                ->orWhereHas('recipe.preparations.preparation.preparationIngredients', fn ($items) => $items->where('ingredient_id', $ingredient->id)))
                ->orderBy('name')->get(),
        ]);
    }

    public function edit(Ingredient $ingredient): View
    {
        $categories = IngredientCategory::query()
            ->where(function ($query) use ($ingredient): void {
                $query->where('active', true);

                if ($ingredient->ingredient_category_id !== null) {
                    $query->orWhere('id', $ingredient->ingredient_category_id);
                }
            })
            ->orderBy('name')
            ->get();

        return view('ingredients.edit', compact('ingredient', 'categories'));
    }

    public function update(IngredientRequest $request, Ingredient $ingredient): RedirectResponse
    {
        if ($ingredient->prices()->exists() && $ingredient->base_unit !== $request->validated('base_unit')) {
            return back()->withErrors([
                'base_unit' => 'A unidade-base não pode ser alterada depois que o insumo possui histórico de preços.',
            ])->withInput();
        }

        $this->catalog->update($ingredient, $request->validated());

        return redirect()->route('ingredients.show', $ingredient)->with('success', 'Insumo atualizado com sucesso.');
    }
}
