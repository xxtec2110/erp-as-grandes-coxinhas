<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentCatalogRequest;
use App\Models\Acquirer;
use Illuminate\Http\RedirectResponse;

class AcquirerController extends Controller
{
    public function store(PaymentCatalogRequest $request): RedirectResponse
    {
        Acquirer::query()->create($request->validated());

        return back()->with('success', 'Adquirente cadastrada.');
    }

    public function update(PaymentCatalogRequest $request, Acquirer $acquirer): RedirectResponse
    {
        $acquirer->update($request->validated());

        return back()->with('success', 'Adquirente atualizada.');
    }
}
