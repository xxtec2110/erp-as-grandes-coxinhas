<?php

namespace App\Http\Requests;

use App\Enums\StockMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(['increase', 'decrease'])],
            'movement_type' => ['required', Rule::in([
                StockMovementType::OpeningBalance->value,
                StockMovementType::Adjustment->value,
            ])],
            'quantity' => ['required', 'decimal:0,6', 'gt:0'],
            'operation_date' => ['required', 'date'],
            'idempotency_key' => ['required', 'uuid'],
            'notes' => ['required', 'string', 'max:2000'],
        ];
    }
}
