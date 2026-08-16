<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\ProductCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(private ProductCatalogService $catalog) {}

    public function index(): View
    {
        return view('products.index', [
            'products' => Product::query()->with(['aliases', 'currentPrice', 'category', 'recipe'])
                ->orderByRaw('CASE WHEN sort_order IS NULL THEN 1 ELSE 0 END')
                ->orderBy('sort_order')->orderBy('name')->paginate(25),
        ]);
    }

    public function create(): View
    {
        return view('products.create', ['categories' => ProductCategory::query()->where('active', true)->orderBy('name')->get()]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $aliases = $data['aliases'] ?? [];
        unset($data['aliases']);
        $this->catalog->create($data, $aliases, $request->user());

        return redirect()->route('products.index')->with('success', 'Produto cadastrado com sucesso.');
    }

    public function edit(Product $product): View
    {
        $product->load(['aliases', 'currentPrice']);

        return view('products.edit', ['product' => $product, 'categories' => ProductCategory::query()->orderBy('name')->get()]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();
        $aliases = $data['aliases'] ?? $product->aliases()->pluck('name')->all();
        unset($data['aliases']);
        $this->catalog->update($product, $data, $aliases, $request->user());

        return redirect()->route('products.index')->with('success', 'Produto atualizado com sucesso.');
    }
}
