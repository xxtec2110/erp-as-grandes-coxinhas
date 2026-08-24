<?php

namespace App\Services;

use App\Models\PurchaseDocument;
use App\Models\PurchaseDocumentItem;
use App\Models\PurchaseReceipt;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PurchaseReceiptService
{
    public function __construct(private AuthorizationService $authorization, private UnitConversionService $units, private IngredientStockService $stock, private FinanceAuditService $audit, private IngredientPriceService $prices) {}

    public function receive(PurchaseDocument $document, string $receivedDate, User $user, string $source = 'web'): PurchaseDocument
    {
        $document->load('items');
        $quantities = $document->items->mapWithKeys(fn ($item) => [$item->id => (string) BigDecimal::of($item->quantity)->minus($item->received_quantity ?? 0)])->all();

        return $this->receivePartial($document, $receivedDate, $quantities, "purchase:{$document->id}:full-receipt", $user, $source);
    }

    public function receivePartial(PurchaseDocument $document, string $receivedDate, array $quantities, string $idempotencyKey, User $user, string $source = 'web'): PurchaseDocument
    {
        $validator = Validator::make([
            'received_date' => $receivedDate,
            'quantities' => $quantities,
            'idempotency_key' => $idempotencyKey,
        ], [
            'received_date' => ['required', 'date'],
            'quantities' => ['required', 'array', 'min:1'],
            'quantities.*' => ['nullable', 'decimal:0,6', 'gte:0'],
            'idempotency_key' => ['required', 'string', 'max:190'],
        ]);
        if ($validator->fails()) {
            throw new DomainException($validator->errors()->first());
        }
        $validated = $validator->validated();
        $receivedDate = $validated['received_date'];
        $quantities = $validated['quantities'];
        $idempotencyKey = $validated['idempotency_key'];
        $this->authorization->authorize($user, 'purchases.receive', $document->location_id);

        return DB::transaction(function () use ($document, $receivedDate, $quantities, $idempotencyKey, $user, $source) {
            $existing = PurchaseReceipt::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->document()->with('items.ingredient')->firstOrFail();
            }
            $document = PurchaseDocument::query()->with('items.ingredient')->lockForUpdate()->findOrFail($document->id);
            if ($document->items->isEmpty() || $document->items->contains(fn (PurchaseDocumentItem $item) => $item->ingredient === null)) {
                throw new DomainException('Todos os itens devem estar vinculados a insumos antes do recebimento.');
            }
            $receipt = PurchaseReceipt::query()->create(['purchase_document_id' => $document->id, 'received_date' => $receivedDate, 'idempotency_key' => $idempotencyKey, 'received_by' => $user->id, 'source' => $source]);
            $receivedAny = false;
            foreach ($document->items as $item) {
                $quantity = BigDecimal::of($quantities[$item->id] ?? 0)->toScale(6, RoundingMode::Unnecessary);
                if ($quantity->isZero()) {
                    continue;
                }if ($quantity->isNegative()) {
                    throw new DomainException('A quantidade recebida não pode ser negativa.');
                }$remaining = BigDecimal::of($item->quantity)->minus($item->received_quantity ?? 0);
                if ($quantity->isGreaterThan($remaining)) {
                    throw new DomainException("O recebimento de {$item->description} supera o saldo pendente.");
                }$receiptItem = $receipt->items()->create(['purchase_document_item_id' => $item->id, 'quantity_received' => (string) $quantity]);
                $normalized = $this->units->normalize((string) $quantity, $item->unit, $item->ingredient->base_unit);
                $this->stock->record(['ingredient_id' => $item->ingredient_id, 'location_id' => $document->location_id, 'type' => 'purchase_receipt', 'quantity_delta' => $normalized, 'operation_date' => $receivedDate, 'reference_type' => get_class($receiptItem), 'reference_id' => $receiptItem->id, 'idempotency_key' => "{$idempotencyKey}:item:{$item->id}", 'created_by' => $user->id, 'source' => $source, 'notes' => "Recebimento parcial do documento #{$document->id}."]);
                $price = $item->priceHistory()->first();
                if ($price === null && $document->supplier_id !== null) {
                    $price = $this->prices->record($item->ingredient, ['supplier_id' => $document->supplier_id, 'location_id' => $document->location_id, 'purchase_document_id' => $document->id, 'purchase_item_id' => $item->id, 'purchase_quantity' => $item->quantity, 'purchase_unit' => $item->unit, 'price_paid' => $item->net_amount ?? $item->total_price, 'gross_total' => $item->gross_amount ?? $item->total_price, 'net_total' => $item->net_amount ?? $item->total_price, 'effective_date' => $document->issue_date->toDateString(), 'purchase_date' => $document->issue_date->toDateString(), 'received_at' => now(), 'source_type' => 'receipt', 'currency' => $document->currency ?? 'BRL', 'created_by' => $user->id, 'source_channel' => $source]);
                } elseif ($price !== null && $price->received_at === null) {
                    $price->update(['received_at' => now()]);
                }
                $item->update(['received_quantity' => (string) BigDecimal::of($item->received_quantity ?? 0)->plus($quantity)->toScale(6)]);
                $receivedAny = true;
            }
            if (! $receivedAny) {
                throw new DomainException('Informe ao menos uma quantidade recebida.');
            }$document->refresh()->load('items');
            $complete = $document->items->every(fn ($item) => BigDecimal::of($item->received_quantity)->isEqualTo($item->quantity));
            $status = $complete ? 'received' : 'partially_received';
            $document->update(['receipt_status' => $status, 'received_date' => $receivedDate, 'received_at' => $complete ? now() : null, 'received_by' => $user->id]);
            $this->audit->record('purchase_document.'.$status, $document, $user, ['receipt_id' => $receipt->id, 'status' => $status], null, $source, $idempotencyKey);

            return $document->refresh()->load('items.ingredient');
        });
    }
}
