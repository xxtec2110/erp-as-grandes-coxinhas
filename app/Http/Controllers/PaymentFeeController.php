<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentFeeBatchRequest;
use App\Models\Acquirer;
use App\Models\CardBrand;
use App\Models\PaymentFee;
use App\Models\PaymentFeeImport;
use App\Services\PaymentFeeImportService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PaymentFeeController extends Controller
{
    public function index(Request $request): View
    {
        $fees = PaymentFee::query()->with(['acquirer', 'cardBrand', 'creator'])->when($request->integer('acquirer_id'), fn ($q, $id) => $q->where('acquirer_id', $id))->when($request->integer('card_brand_id'), fn ($q, $id) => $q->where('card_brand_id', $id))->when($request->string('payment_method')->isNotEmpty(), fn ($q) => $q->where('payment_method', $request->string('payment_method')))->when($request->string('scope')->toString() !== 'history', fn ($q) => $q->where('is_current', true))->latest('effective_from')->paginate(30)->withQueryString();

        return view('payment-fees.index', ['fees' => $fees, 'acquirers' => Acquirer::query()->orderBy('name')->get(), 'brands' => CardBrand::query()->orderBy('name')->get()]);
    }

    public function batch(): View
    {
        return view('payment-fees.batch', ['acquirers' => Acquirer::query()->where('active', true)->orderBy('name')->get(), 'brands' => CardBrand::query()->where('active', true)->orderBy('name')->get(), 'idempotencyKey' => (string) Str::uuid()]);
    }

    public function preview(PaymentFeeBatchRequest $request, PaymentFeeImportService $service): RedirectResponse
    {
        $data = $request->validated();
        $rows = collect($data['rows'])->map(fn ($row) => [...$row, 'acquirer_id' => $data['acquirer_id'], 'effective_from' => $data['effective_from'], 'fixed_fee' => $row['fixed_fee'] ?? '0'])->all();
        $import = $service->preview($rows, $request->user(), $data['idempotency_key']);

        return redirect()->route('payment-fees.imports.show', $import);
    }

    public function showImport(PaymentFeeImport $import): View
    {
        return view('payment-fees.preview', ['import' => $import->load('acquirer'), 'brands' => CardBrand::query()->pluck('name', 'id'), 'currentFees' => PaymentFee::query()->where('is_current', true)->get()->keyBy(fn ($fee) => implode(':', [$fee->acquirer_id, $fee->card_brand_id, $fee->payment_method, $fee->installments]))]);
    }

    public function confirm(PaymentFeeImport $import, Request $request, PaymentFeeImportService $service): RedirectResponse
    {
        try {
            $service->confirm($import, $request->user());
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['import' => $e->getMessage()]);
        }

return redirect()->route('payment-fees.index')->with('success', 'Lote confirmado e aplicado integralmente.');
    }

    public function reject(PaymentFeeImport $import, Request $request, PaymentFeeImportService $service): RedirectResponse
    {
        $service->reject($import, $request->user());

        return redirect()->route('payment-fees.index')->with('success', 'Importação rejeitada sem alterar taxas.');
    }
}
