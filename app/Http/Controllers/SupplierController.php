<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupplierRequest;
use App\Models\Supplier;
use App\Services\SupplierCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function __construct(private SupplierCatalogService $catalog) {}

    public function index(): View
    {
        return view('suppliers.index', ['suppliers' => Supplier::query()->orderBy('name')->paginate(15)]);
    }

    public function create(): View
    {
        return view('suppliers.create');
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        $this->catalog->create($request->validated());

        return redirect()->route('suppliers.index')->with('success', 'Fornecedor cadastrado com sucesso.');
    }

    public function edit(Supplier $supplier): View
    {
        return view('suppliers.edit', compact('supplier'));
    }

    public function update(SupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->catalog->update($supplier, $request->validated());

        return redirect()->route('suppliers.index')->with('success', 'Fornecedor atualizado com sucesso.');
    }
}
