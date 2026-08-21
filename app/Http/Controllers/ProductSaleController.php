<?php

namespace App\Http\Controllers;

use App\Enums\ProductSalePaymentMethod;
use App\Http\Requests\ProductSaleRequest;
use App\Models\Acquirer;
use App\Models\CardBrand;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductSale;
use App\Services\AuthorizationService;
use App\Services\ProductSaleService;
use App\Services\SalesSummaryService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductSaleController extends Controller
{
    public function index(Request $request, AuthorizationService $auth, SalesSummaryService $summary): View
    {
        $locations = $auth->accessibleLocations($request->user())->where('type', Location::TYPE_STORE)->values();
        $requestedId = $request->integer('location_id');
        if ($request->has('location_id') && ! $locations->contains('id', $requestedId)) {
            abort(403, 'Você não possui acesso a esta unidade comercial.');
        }
        $location = $locations->firstWhere('id', $requestedId)
            ?? $locations->firstWhere('id', $request->user()->default_location_id)
            ?? $locations->first();
        $start = $request->date('start_date')?->toDateString() ?? now()->startOfMonth()->toDateString();
        $end = $request->date('end_date')?->toDateString() ?? now()->toDateString();
        $paymentMethod = in_array($request->string('payment_method')->toString(), ProductSalePaymentMethod::values(), true) ? $request->string('payment_method')->toString() : null;

        return view('sales.index', ['sales' => ProductSale::query()->with(['product.category', 'location', 'creator', 'acquirer', 'cardBrand'])->when($location, fn ($query) => $query->where('location_id', $location->id))->when($paymentMethod, fn ($query) => $query->where('payment_method', $paymentMethod))->latest('operation_date')->paginate(20)->withQueryString(), 'locations' => $locations, 'location' => $location, 'startDate' => $start, 'endDate' => $end, 'paymentMethod' => $paymentMethod, 'paymentMethods' => ProductSalePaymentMethod::cases(), 'summary' => $location ? $summary->summarize($location, $start, $end, $paymentMethod) : []]);
    }

    public function create(Request $request, AuthorizationService $auth): View
    {
        return view('sales.create', ['products' => Product::query()->where('active', true)->with('category')->orderBy('name')->get(), 'locations' => $auth->accessibleLocations($request->user())->where('type', Location::TYPE_STORE), 'acquirers' => Acquirer::query()->where('active', true)->orderBy('name')->get(), 'brands' => CardBrand::query()->where('active', true)->orderBy('name')->get(), 'paymentMethods' => ProductSalePaymentMethod::cases(), 'idempotencyKey' => (string) Str::uuid()]);
    }

    public function store(ProductSaleRequest $request, ProductSaleService $service): RedirectResponse
    {
        try {
            $service->record($request->validated(), $request->user());
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }

        return redirect()->route('sales.index')->with('success', 'Venda registrada e estoque atualizado.');
    }
}
