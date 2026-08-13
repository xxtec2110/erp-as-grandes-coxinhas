<?php

namespace App\Services;

use App\Models\PurchaseDocument;
use App\Models\PurchaseDocumentItem;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class PurchaseReceiptService
{
    public function __construct(private AuthorizationService $authorization, private UnitConversionService $units, private IngredientStockService $stock, private FinanceAuditService $audit) {}

    public function receive(PurchaseDocument $document, string $receivedDate, User $user, string $source = 'web'): PurchaseDocument
    {
        $this->authorization->authorize($user, 'purchases.receive', $document->location_id);

        return DB::transaction(function () use ($document, $receivedDate, $user, $source): PurchaseDocument {
            $document = PurchaseDocument::query()->with(['items.ingredient'])->lockForUpdate()->findOrFail($document->id);
            if ($document->receipt_status === 'received') {
                if ($document->received_date?->toDateString() !== $receivedDate) {
                    throw new DomainException('Este documento já foi recebido em outra data.');
                }

                return $document;
            }
            if ($document->items->isEmpty() || $document->items->contains(fn (PurchaseDocumentItem $item) => $item->ingredient === null)) {
                throw new DomainException('Todos os itens devem estar vinculados a insumos antes do recebimento.');
            }
            foreach ($document->items as $item) {
                $normalized = $this->units->normalize((string) $item->quantity, $item->unit, $item->ingredient->base_unit);
                $this->stock->record(['ingredient_id' => $item->ingredient_id, 'location_id' => $document->location_id, 'type' => 'purchase_receipt', 'quantity_delta' => $normalized, 'operation_date' => $receivedDate, 'reference_type' => PurchaseDocument::class, 'reference_id' => $document->id, 'idempotency_key' => "purchase:{$document->id}:item:{$item->id}:received", 'created_by' => $user->id, 'notes' => "Recebimento do documento #{$document->id}."]);
            }
            $before = $document->toArray();
            $document->update(['receipt_status' => 'received', 'received_date' => $receivedDate, 'received_at' => now(), 'received_by' => $user->id]);
            $this->audit->record('purchase_document.received', $document, $user, $document->fresh()->toArray(), $before, $source, "purchase:{$document->id}:received");

            return $document->refresh()->load('items.ingredient');
        });
    }
}
