<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PartialPurchaseReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['received_date' => ['required', 'date'], 'idempotency_key' => ['required', 'uuid'], 'quantities' => ['required', 'array'], 'quantities.*' => ['nullable', 'decimal:0,6', 'gte:0']];
    }
}
