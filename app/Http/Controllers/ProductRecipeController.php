<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRecipeRequest;
use App\Models\Ingredient;
use App\Models\Preparation;
use App\Models\Product;
use App\Services\ProductRecipeCostService;
use App\Services\ProductRecipeService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProductRecipeController extends Controller
{
    public function edit(Product $product, ProductRecipeCostService $costs): View
    {
        $product->load('currentPrice');
        $recipe = $product->recipe()->with(['ingredients.ingredient', 'preparations.preparation'])->first();

        return view('products.recipe', ['product' => $product, 'recipe' => $recipe, 'ingredients' => Ingredient::query()->where('active', true)->orderBy('name')->get(), 'preparations' => Preparation::query()->where('active', true)->orderBy('name')->get(), 'costs' => $recipe ? $costs->calculate($recipe) : null]);
    }

    public function update(ProductRecipeRequest $request, Product $product, ProductRecipeService $service): RedirectResponse
    {
        try {
            $service->save($product, $request->validated(), $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['recipe' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', 'Ficha técnica atualizada.');
    }
}
