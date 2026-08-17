<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseDocumentImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'document_type' => ['required', Rule::in(['purchase_invoice', 'purchase_receipt', 'purchase_order', 'quotation'])],
            'document_number' => ['nullable', 'string', 'max:100'],
            'series' => ['nullable', 'string', 'max:30'],
            'access_key' => ['nullable', 'string', 'max:80'],
            'issue_date' => ['required', 'date'],
            'gross_amount' => ['nullable', 'decimal:0,2', 'gte:0'],
            'discount_amount' => ['required', 'decimal:0,2', 'gte:0'],
            'freight_amount' => ['required', 'decimal:0,2', 'gte:0'],
            'other_charges_amount' => ['required', 'decimal:0,2', 'gte:0'],
            'total_amount' => ['required', 'decimal:0,2', 'gt:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.external_code' => ['nullable', 'string', 'max:100'],
            'items.*.quantity' => ['required', 'decimal:0,6', 'gt:0'],
            'items.*.unit' => ['required', Rule::in(['kg', 'g', 'l', 'ml', 'un'])],
            'items.*.package_quantity' => ['nullable', 'required_with:items.*.package_size,items.*.package_unit', 'decimal:0,6', 'gt:0'],
            'items.*.package_size' => ['nullable', 'required_with:items.*.package_quantity,items.*.package_unit', 'decimal:0,6', 'gt:0'],
            'items.*.package_unit' => ['nullable', 'required_with:items.*.package_quantity,items.*.package_size', Rule::in(['kg', 'g', 'l', 'ml', 'un'])],
            'items.*.gross_amount' => ['required', 'decimal:0,2', 'gte:0'],
            'items.*.discount_amount' => ['required', 'decimal:0,2', 'gte:0'],
            'items.*.freight_amount' => ['required', 'decimal:0,2', 'gte:0'],
            'items.*.other_charges_amount' => ['required', 'decimal:0,2', 'gte:0'],
            'items.*.net_amount' => ['required', 'decimal:0,2', 'gte:0'],
            'items.*.save_mapping' => ['nullable', 'boolean'],
            'received' => ['nullable', 'boolean'],
            'received_date' => ['nullable', 'required_if:received,1', 'date'],
        ];
    }
}
