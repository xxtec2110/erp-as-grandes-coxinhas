<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductStockPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['active' => $this->boolean('active')]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', Rule::exists('products', 'id')],
            'location_id' => ['required', Rule::exists('locations', 'id')],
            'minimum_quantity' => ['nullable', 'decimal:0,6', 'gte:0'],
            'target_quantity' => ['required', 'decimal:0,6', 'gte:0', 'gte:minimum_quantity'],
            'production_priority' => ['required', 'integer', 'between:0,100'],
            'active' => ['required', 'boolean'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
