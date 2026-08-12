<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'received_date' => ['required', 'date'],
            'received_quantities' => ['required', 'array'],
            'received_quantities.*' => ['required', 'decimal:0,6', 'gte:0'],
        ];
    }
}
