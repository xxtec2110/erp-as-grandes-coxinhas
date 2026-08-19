<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpeningStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('active', true)],
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('active', true)],
            'quantity' => ['required', 'decimal:0,6', 'gt:0'],
            'operation_date' => ['required', 'date'],
            'notes' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
