<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(): View
    {
        return view('products.index', [
            'products' => Product::query()->orderBy('name')->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('products.create', ['categories' => ProductCategory::query()->where('active', true)->orderBy('name')->get()]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        Product::query()->create($request->validated());

        return redirect()->route('products.index')->with('success', 'Produto cadastrado com sucesso.');
    }

    public function edit(Product $product): View
    {
        return view('products.edit', ['product' => $product, 'categories' => ProductCategory::query()->orderBy('name')->get()]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();

        if ($product->stockMovements()->exists() && $data['stock_unit'] !== $product->stock_unit) {
            throw ValidationException::withMessages([
                'stock_unit' => 'A unidade de estoque não pode ser alterada após o primeiro movimento.',
            ]);
        }

        $product->update($data);

        return redirect()->route('products.index')->with('success', 'Produto atualizado com sucesso.');
    }
}
