<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\ProductAliasService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(private ProductAliasService $aliases) {}

    public function index(): View
    {
        return view('products.index', [
            'products' => Product::query()->with('aliases')->orderBy('name')->paginate(15),
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

        DB::transaction(function () use ($data, $aliases): void {
            $product = Product::query()->create($data);
            $this->aliases->sync($product, $aliases);
        });

        return redirect()->route('products.index')->with('success', 'Produto cadastrado com sucesso.');
    }

    public function edit(Product $product): View
    {
        $product->load('aliases');

        return view('products.edit', ['product' => $product, 'categories' => ProductCategory::query()->orderBy('name')->get()]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();
        $aliases = $data['aliases'] ?? $product->aliases()->pluck('name')->all();
        unset($data['aliases']);

        if ($product->stockMovements()->exists() && $data['stock_unit'] !== $product->stock_unit) {
            throw ValidationException::withMessages([
                'stock_unit' => 'A unidade de estoque não pode ser alterada após o primeiro movimento.',
            ]);
        }

        DB::transaction(function () use ($product, $data, $aliases): void {
            $product->update($data);
            $this->aliases->sync($product, $aliases);
        });

        return redirect()->route('products.index')->with('success', 'Produto atualizado com sucesso.');
    }
}
