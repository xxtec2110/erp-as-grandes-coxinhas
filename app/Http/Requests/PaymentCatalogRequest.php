<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['active' => $this->boolean('active')]);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:60'], 'active' => ['required', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000']];
    }
}
