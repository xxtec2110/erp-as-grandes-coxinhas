<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentCatalogRequest;
use App\Models\CardBrand;
use Illuminate\Http\RedirectResponse;

class CardBrandController extends Controller
{
    public function store(PaymentCatalogRequest $request): RedirectResponse
    {
        CardBrand::query()->create($request->validated());

        return back()->with('success', 'Bandeira cadastrada.');
    }

    public function update(PaymentCatalogRequest $request, CardBrand $cardBrand): RedirectResponse
    {
        $cardBrand->update($request->validated());

        return back()->with('success', 'Bandeira atualizada.');
    }
}
