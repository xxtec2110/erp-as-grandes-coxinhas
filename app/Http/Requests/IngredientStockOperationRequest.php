<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IngredientStockOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['ingredient_id' => ['required', 'exists:ingredients,id'], 'location_id' => ['required', 'exists:locations,id'], 'quantity' => ['required', 'decimal:0,6', 'gt:0'], 'unit' => ['required', 'in:kg,g,l,ml,un'], 'operation_date' => ['required', 'date'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'direction' => ['nullable', 'in:positive,negative'], 'idempotency_key' => ['required', 'uuid']];
    }
}
