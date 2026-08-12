<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreparationAdditionalCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
        ];
    }
}
