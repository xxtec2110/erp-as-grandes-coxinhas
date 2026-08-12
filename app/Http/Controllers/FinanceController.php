<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinanceCatalogRequest;
use App\Http\Requests\PayableRequest;
use App\Http\Requests\PaymentRequest;
use App\Models\CostCenter;
use App\Models\FinanceCategory;
use App\Models\FinancialAccount;
use App\Models\Payable;
use App\Models\Supplier;
use App\Services\AuthorizationService;
use App\Services\CreatePayableService;
use App\Services\FinanceReportService;
use App\Services\RegisterPaymentService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FinanceController
{
    public function index(Request $r, AuthorizationService $a, FinanceReportService $reports): View
    {
        $locations = $a->accessibleLocations($r->user());
        $start = $r->date('start')?->toDateString() ?? now()->startOfMonth()->toDateString();
        $end = $r->date('end')?->toDateString() ?? now()->toDateString();

        return view('finance.index', ['payables' => Payable::query()->with(['supplier', 'location', 'payments'])->whereIn('location_id', $locations->pluck('id'))->latest('due_date')->paginate(20), 'summary' => $reports->summary($locations->pluck('id')->all(), $start, $end), 'locations' => $locations, 'start' => $start, 'end' => $end]);
    }

    public function create(Request $r, AuthorizationService $a): View
    {
        return view('finance.create', ['suppliers' => Supplier::query()->orderBy('name')->get(), 'locations' => $a->accessibleLocations($r->user()), 'categories' => FinanceCategory::query()->where('active', true)->get(), 'centers' => CostCenter::query()->where('active', true)->get(), 'key' => (string) Str::uuid()]);
    }

    public function store(PayableRequest $r, CreatePayableService $s): RedirectResponse
    {
        $s->create($r->validated(), $r->user());

        return redirect()->route('finance.index')->with('success', 'Conta cadastrada.');
    }

    public function payment(Payable $payable): View
    {
        return view('finance.payment', ['payable' => $payable->load('supplier'), 'accounts' => FinancialAccount::query()->where('active', true)->get(), 'key' => (string) Str::uuid()]);
    }

    public function pay(PaymentRequest $r, Payable $payable, RegisterPaymentService $s): RedirectResponse
    {
        try {
            $s->register($payable, $r->validated(), $r->user());
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

return redirect()->route('finance.index')->with('success', 'Pagamento registrado.');
    }

    public function settings(): View
    {
        return view('finance.settings', ['accounts' => FinancialAccount::query()->get(), 'categories' => FinanceCategory::query()->get(), 'centers' => CostCenter::query()->get()]);
    }

    public function account(FinanceCatalogRequest $r): RedirectResponse
    {
        FinancialAccount::query()->create($r->validated() + ['type' => $r->validated('type') ?? 'bank']);

        return back()->with('success', 'Conta financeira cadastrada.');
    }

    public function category(FinanceCatalogRequest $r): RedirectResponse
    {
        FinanceCategory::query()->create($r->safe()->only(['name', 'active', 'notes']));

        return back()->with('success', 'Categoria cadastrada.');
    }

    public function center(FinanceCatalogRequest $r): RedirectResponse
    {
        CostCenter::query()->create($r->safe()->only(['name', 'location_id', 'active', 'notes']));

        return back()->with('success','Centro cadastrado.');
    }
}
