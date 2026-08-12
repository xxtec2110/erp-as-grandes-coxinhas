<?php

namespace App\Http\Controllers;

use App\Http\Requests\GlpPriceRequest;
use App\Models\GlpProduct;
use App\Services\GlpPriceService;
use Illuminate\Http\RedirectResponse;

class GlpPriceController extends Controller
{
    public function store(
        GlpPriceRequest $request,
        GlpProduct $glpProduct,
        GlpPriceService $priceService,
    ): RedirectResponse {
        $priceService->record($glpProduct, $request->validated());

        return redirect()->route('glp-products.show', $glpProduct)
            ->with('success', 'Preço do GLP registrado e custo por kg calculado.');
    }
}
