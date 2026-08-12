<?php

namespace App\Http\Controllers;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Http\Requests\StockAdjustmentRequest;
use App\Models\Location;
use App\Models\Product;
use App\Services\StockBalanceService;
use App\Services\StockMovementService;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StockAdjustmentController extends Controller
{
    public function create(Product $product, Location $location, StockBalanceService $balances): View
    {
        return view('stock.adjust', [
            'product' => $product,
            'location' => $location,
            'balance' => $balances->balance($product, $location),
            'idempotencyKey' => (string) Str::uuid(),
            'movementType' => $product->stockMovements()->whereBelongsTo($location)->exists()
                ? StockMovementType::Adjustment
                : StockMovementType::OpeningBalance,
        ]);
    }

    public function store(
        StockAdjustmentRequest $request,
        Product $product,
        Location $location,
        StockMovementService $movements,
    ): RedirectResponse {
        $data = $request->validated();
        $quantity = BigDecimal::of($data['quantity']);

        $movements->record(new RecordStockMovementData(
            productId: $product->getKey(),
            locationId: $location->getKey(),
            type: StockMovementType::from($data['movement_type']),
            quantityDelta: (string) ($data['direction'] === 'decrease' ? $quantity->negated() : $quantity),
            operationDate: $data['operation_date'],
            idempotencyKey: $data['idempotency_key'],
            createdBy: $request->user()?->getKey(),
            notes: $data['notes'],
        ));

        return redirect()->route('stock.show', [$product, $location])
            ->with('success', 'Movimento de estoque registrado com sucesso.');
    }
}
