<?php

namespace App\Services;

use App\Models\PurchaseDocument;
use App\Models\PurchaseDocumentItem;
use App\Models\User;

class PurchaseDocumentActionService
{
    public function __construct(private AuthorizationService $authorization, private FinanceAuditService $audit, private UnitConversionService $conversion) {}

    public function linkSupplier(PurchaseDocument $document, int $supplierId, User $user, string $source = 'agent'): PurchaseDocument
    {
        $this->authorization->authorize($user, 'purchases.approve', $document->location_id);
        $old = $document->toArray();
        $document->update(['supplier_id' => $supplierId]);
        $this->audit->record('purchase_document.supplier_linked', $document, $user, $document->toArray(), $old, $source);

        return $document->refresh();
    }

    public function ingredientPriceSuggestion(PurchaseDocumentItem $item, User $user): array
    {
        $document = PurchaseDocument::query()->findOrFail($item->purchase_document_id);
        $this->authorization->authorize($user, 'ingredient_prices.update', $document->location_id);
        abort_if($item->ingredient_id === null, 422, 'O item não está vinculado a um insumo.');
        $ingredient = $item->ingredient()->firstOrFail();
        $normalized = $this->conversion->normalize($item->quantity, $item->unit, $ingredient->base_unit);

        return ['ingredient_id' => $ingredient->id, 'quantity_base' => $normalized, 'total_price' => $item->total_price, 'base_unit_cost' => $this->conversion->calculateBaseUnitCost($item->total_price, $normalized), 'requires_confirmation' => true];
    }
}
