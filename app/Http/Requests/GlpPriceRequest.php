<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GlpPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_current' => $this->boolean('is_current')]);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'quantity_kg' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'total_price' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
            'effective_date' => ['required', 'date'],
            'is_current' => ['required', 'boolean'],
        ];
    }
}
