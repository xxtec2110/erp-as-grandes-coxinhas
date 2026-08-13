<?php

namespace App\Services;

use App\Models\Payable;
use App\Models\PurchaseDocument;
use App\Models\User;
use DomainException;

class PurchasePayableService
{
    public function __construct(private CreatePayableService $payables) {}

    public function create(PurchaseDocument $document, User $user, string $source = 'web'): Payable
    {
        $existing = Payable::query()->where('purchase_document_id', $document->id)->first();
        if ($existing) {
            return $existing;
        }
        $missing = collect(['supplier_id', 'due_date', 'finance_category_id', 'cost_center_id'])->filter(fn ($field) => blank($document->{$field}))->all();
        if ($missing !== []) {
            throw new DomainException('Complete antes: '.implode(', ', $missing).'.');
        }

        return $this->payables->create(['supplier_id' => $document->supplier_id, 'description' => 'Documento de compra '.($document->document_number ?: '#'.$document->id), 'purchase_document_id' => $document->id, 'location_id' => $document->location_id, 'cost_center_id' => $document->cost_center_id, 'finance_category_id' => $document->finance_category_id, 'expected_amount' => $document->total_amount, 'competency_date' => $document->issue_date->toDateString(), 'due_date' => $document->due_date->toDateString(), 'recurring' => false, 'idempotency_key' => "purchase-document:{$document->id}:payable"], $user, $source);
    }
}
