<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductLossRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', Rule::exists('products', 'id')->where('active', true)],
            'location_id' => ['required', Rule::exists('locations', 'id')->where('active', true)],
            'loss_reason_id' => ['required', Rule::exists('loss_reasons', 'id')->where('active', true)],
            'quantity' => ['required', 'decimal:0,6', 'gt:0'],
            'operation_date' => ['required', 'date'],
            'idempotency_key' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
