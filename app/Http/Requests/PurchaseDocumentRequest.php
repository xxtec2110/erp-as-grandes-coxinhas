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
        return [
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'document_type' => ['required', Rule::in(['invoice', 'boleto', 'bill', 'receipt', 'proof', 'order', 'quote', 'other'])],
            'document_number' => ['nullable', 'string', 'max:100'], 'series' => ['nullable', 'string', 'max:30'], 'access_key' => ['nullable', 'string', 'max:80'],
            'issue_date' => ['required', 'date'], 'due_date' => ['nullable', 'date'], 'currency' => ['nullable', Rule::in(['BRL'])],
            'gross_amount' => ['nullable', 'decimal:0,2', 'gte:0'], 'discount_amount' => ['nullable', 'decimal:0,2', 'gte:0'], 'freight_amount' => ['nullable', 'decimal:0,2', 'gte:0'], 'other_charges_amount' => ['nullable', 'decimal:0,2', 'gte:0'],
            'total_amount' => ['required', 'decimal:0,2', 'gt:0'], 'location_id' => ['required', 'exists:locations,id'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,id'], 'finance_category_id' => ['nullable', 'exists:finance_categories,id'], 'agent_attachment_id' => ['nullable', 'exists:agent_attachments,id'],
            'notes' => ['nullable', 'string'], 'idempotency_key' => ['required', 'uuid'], 'items' => ['array'],
            'items.*.ingredient_id' => ['nullable', 'exists:ingredients,id'], 'items.*.product_id' => ['nullable', 'exists:products,id'],
            'items.*.external_code' => ['nullable', 'string', 'max:100'], 'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'decimal:0,6', 'gt:0'], 'items.*.unit' => ['required', Rule::in(['kg', 'g', 'l', 'ml', 'un'])],
            'items.*.package_quantity' => ['nullable', 'required_with:items.*.package_size,items.*.package_unit', 'decimal:0,6', 'gt:0'],
            'items.*.package_size' => ['nullable', 'required_with:items.*.package_quantity,items.*.package_unit', 'decimal:0,6', 'gt:0'],
            'items.*.package_unit' => ['nullable', 'required_with:items.*.package_quantity,items.*.package_size', Rule::in(['kg', 'g', 'l', 'ml', 'un'])],
            'items.*.unit_price' => ['required', 'decimal:0,4', 'gte:0'], 'items.*.total_price' => ['required', 'decimal:0,2', 'gte:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))->filter(fn ($item) => is_array($item) && filled($item['description'] ?? null))->values()->all();
        $this->merge(['items' => $items, 'currency' => $this->input('currency', 'BRL')]);
    }
}
