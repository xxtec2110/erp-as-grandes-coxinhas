<?php

namespace App\Http\Controllers;

use App\Http\Requests\GlpProductRequest;
use App\Models\GlpProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class GlpProductController extends Controller
{
    public function index(): View
    {
        return view('glp.index', [
            'products' => GlpProduct::query()->with('currentPrice')->orderBy('name')->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('glp.create');
    }

    public function store(GlpProductRequest $request): RedirectResponse
    {
        $product = GlpProduct::query()->create($request->validated());

        return redirect()->route('glp-products.show', $product)
            ->with('success', 'Recipiente de GLP cadastrado. Agora registre o primeiro preço.');
    }

    public function show(GlpProduct $glpProduct): View
    {
        $glpProduct->load([
            'currentPrice',
            'prices' => fn ($query) => $query->latest('effective_date')->latest('id'),
        ]);

        return view('glp.show', compact('glpProduct'));
    }

    public function edit(GlpProduct $glpProduct): View
    {
        return view('glp.edit', compact('glpProduct'));
    }

    public function update(GlpProductRequest $request, GlpProduct $glpProduct): RedirectResponse
    {
        $glpProduct->update($request->validated());

        return redirect()->route('glp-products.show', $glpProduct)
            ->with('success', 'Recipiente de GLP atualizado com sucesso.');
    }
}
