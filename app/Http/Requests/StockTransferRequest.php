<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'source_location_id' => ['required', Rule::exists('locations', 'id')->where('active', true)],
            'destination_location_id' => ['required', 'different:source_location_id', Rule::exists('locations', 'id')->where('active', true)],
            'product_id' => ['required', Rule::exists('products', 'id')->where('active', true)],
            'quantity' => ['required', 'decimal:0,6', 'gt:0'],
            'operation_date' => ['required', 'date'],
            'idempotency_key' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
