<?php

namespace App\Http\Controllers;

use App\Http\Requests\DispatchStockTransferRequest;
use App\Http\Requests\ReceiveStockTransferRequest;
use App\Http\Requests\ReverseStockTransferRequest;
use App\Http\Requests\StockTransferRequest;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Services\AuthorizationService;
use App\Services\StockTransferService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StockTransferController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): View
    {
        $locationIds = $authorization->accessibleLocations($request->user())->pluck('id');

        return view('transfers.index', [
            'transfers' => StockTransfer::query()
                ->with(['sourceLocation', 'destinationLocation', 'items.product'])
                ->where(fn ($query) => $query->whereIn('source_location_id', $locationIds)->orWhereIn('destination_location_id', $locationIds))
                ->orderByDesc('operation_date')
                ->orderByDesc('id')
                ->paginate(20),
        ]);
    }

    public function create(Request $request, AuthorizationService $authorization): View
    {
        return view('transfers.create', [
            'products' => Product::query()->where('active', true)->orderBy('name')->get(),
            'locations' => $authorization->accessibleLocations($request->user()),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(StockTransferRequest $request, StockTransferService $service, AuthorizationService $authorization): RedirectResponse
    {
        $authorization->authorize($request->user(), 'transfers.create', (int) $request->validated('source_location_id'));
        $authorization->authorize($request->user(), 'transfers.create', (int) $request->validated('destination_location_id'));
        $transfer = $service->create($request->validated(), $request->user()?->getKey());

        return redirect()->route('transfers.show', $transfer)
            ->with('success', 'Transferência criada. Confira os dados antes de expedir.');
    }

    public function show(StockTransfer $transfer, Request $request, AuthorizationService $authorization): View
    {
        if (! $authorization->allows($request->user(), 'transfers.view', $transfer->source_location_id)
            && ! $authorization->allows($request->user(), 'transfers.view', $transfer->destination_location_id)) {
            abort(403);
        }

        return view('transfers.show', [
            'transfer' => $transfer->load(['sourceLocation', 'destinationLocation', 'items.product', 'creator']),
            'canDispatch' => $authorization->allows($request->user(), 'transfers.create', $transfer->source_location_id),
            'canCancel' => $authorization->allows($request->user(), 'transfers.cancel', $transfer->source_location_id),
            'canReceive' => $authorization->allows($request->user(), 'transfers.receive', $transfer->destination_location_id),
            'canReverse' => $authorization->allows($request->user(), 'transfers.cancel', $transfer->source_location_id)
                && $authorization->allows($request->user(), 'transfers.cancel', $transfer->destination_location_id),
        ]);
    }

    public function dispatch(
        DispatchStockTransferRequest $request,
        StockTransfer $transfer,
        StockTransferService $service,
        AuthorizationService $authorization,
    ): RedirectResponse {
        $authorization->authorize($request->user(), 'transfers.cancel', $transfer->source_location_id);
        try {
            $service->dispatch($transfer, $request->validated('dispatched_date'), $request->user()?->getKey());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['transfer' => $exception->getMessage()]);
        }

        return redirect()->route('transfers.show', $transfer)
            ->with('success', 'Transferência expedida e estoque da origem atualizado.');
    }

    public function receive(
        ReceiveStockTransferRequest $request,
        StockTransfer $transfer,
        StockTransferService $service,
        AuthorizationService $authorization,
    ): RedirectResponse {
        $authorization->authorize($request->user(), 'transfers.receive', $transfer->destination_location_id);
        try {
            $service->receive(
                $transfer,
                $request->validated('received_date'),
                $request->validated('received_quantities'),
                $request->user()?->getKey(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['transfer' => $exception->getMessage()]);
        }

        return redirect()->route('transfers.show', $transfer)
            ->with('success', 'Transferência recebida e estoque do destino atualizado.');
    }

    public function cancel(StockTransfer $transfer, StockTransferService $service, Request $request, AuthorizationService $authorization): RedirectResponse
    {
        $authorization->authorize($request->user(), 'transfers.create', $transfer->source_location_id);
        try {
            $service->cancel($transfer, request()->user()?->getKey());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['transfer' => $exception->getMessage()]);
        }

        return redirect()->route('transfers.show', $transfer)
            ->with('success', 'Transferência cancelada.');
    }

    public function reverse(
        ReverseStockTransferRequest $request,
        StockTransfer $transfer,
        StockTransferService $service,
        AuthorizationService $authorization,
    ): RedirectResponse {
        $authorization->authorize($request->user(), 'transfers.cancel', $transfer->source_location_id);
        $authorization->authorize($request->user(), 'transfers.cancel', $transfer->destination_location_id);
        try {
            $service->reverse(
                $transfer,
                $request->validated('reversal_date'),
                $request->validated('reason'),
                $request->user()?->getKey(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['transfer' => $exception->getMessage()]);
        }

        return redirect()->route('transfers.show', $transfer)
            ->with('success', 'Transferência estornada por movimentos compensatórios auditáveis.');
    }
}
