<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductCategoryRequest;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProductCategoryController extends Controller
{
    public function index(): View
    {
        return view('product-categories.index', ['categories' => ProductCategory::query()->withCount('products')->orderBy('name')->get()]);
    }

    public function store(ProductCategoryRequest $request): RedirectResponse
    {
        ProductCategory::query()->create($request->validated());

        return back()->with('success', 'Categoria cadastrada.');
    }

    public function update(ProductCategoryRequest $request, ProductCategory $productCategory): RedirectResponse
    {
        $productCategory->update($request->validated());

        return back()->with('success', 'Categoria atualizada.');
    }
}
