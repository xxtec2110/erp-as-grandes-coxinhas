<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PayableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['recurring' => $this->boolean('recurring')]);
    }

    public function rules(): array
    {
        return ['supplier_id' => ['nullable', 'exists:suppliers,id'], 'description' => ['required', 'string', 'max:255'], 'purchase_document_id' => ['nullable', 'exists:purchase_documents,id'], 'location_id' => ['required', 'exists:locations,id'], 'cost_center_id' => ['nullable', 'exists:cost_centers,id'], 'finance_category_id' => ['nullable', 'exists:finance_categories,id'], 'expected_amount' => ['required', 'decimal:0,2', 'gt:0'], 'competency_date' => ['required', 'date'], 'due_date' => ['required', 'date'], 'recurring' => ['required', 'boolean'], 'recurrence_rule' => ['nullable', 'required_if:recurring,true', 'in:weekly,biweekly,monthly,annual'], 'notes' => ['nullable', 'string'], 'idempotency_key' => ['required', 'uuid']];
    }
}
