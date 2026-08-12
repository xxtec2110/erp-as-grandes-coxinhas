<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['supplier_id' => ['nullable', 'exists:suppliers,id'], 'document_type' => ['required', Rule::in(['invoice', 'boleto', 'bill', 'receipt', 'proof', 'other'])], 'document_number' => ['nullable', 'string', 'max:100'], 'issue_date' => ['required', 'date'], 'due_date' => ['nullable', 'date'], 'total_amount' => ['required', 'decimal:0,2', 'gt:0'], 'location_id' => ['required', 'exists:locations,id'], 'cost_center_id' => ['nullable', 'exists:cost_centers,id'], 'finance_category_id' => ['nullable', 'exists:finance_categories,id'], 'agent_attachment_id' => ['nullable', 'exists:agent_attachments,id'], 'notes' => ['nullable', 'string'], 'idempotency_key' => ['required', 'uuid'], 'items' => ['array'], 'items.*.ingredient_id' => ['nullable', 'exists:ingredients,id'], 'items.*.product_id' => ['nullable', 'exists:products,id'], 'items.*.description' => ['required_with:items.*.quantity', 'string'], 'items.*.quantity' => ['nullable', 'decimal:0,6', 'gt:0'], 'items.*.unit' => ['nullable', 'string', 'max:10'], 'items.*.unit_price' => ['nullable', 'decimal:0,4', 'gte:0'], 'items.*.total_price' => ['nullable', 'decimal:0,2', 'gte:0']];
    }
}
