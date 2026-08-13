<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['items' => array_values(array_filter($this->input('items', []), fn (array $item) => filled($item['planned_quantity'] ?? null)))]);
    }

    public function rules(): array
    {
        return ['location_id' => ['required', 'exists:locations,id'], 'production_date' => ['required', 'date'], 'idempotency_key' => ['required', 'uuid'], 'notes' => ['nullable', 'string', 'max:2000'], 'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'distinct', 'exists:products,id'], 'items.*.planned_quantity' => ['required', 'decimal:0,6', 'gt:0']];
    }
}
