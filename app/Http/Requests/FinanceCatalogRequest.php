<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinanceCatalogRequest extends FormRequest
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
        return ['name' => ['required', 'string', 'max:255'], 'institution' => ['nullable', 'string'], 'type' => ['nullable', 'string', 'max:40'], 'owner_name' => ['nullable', 'string'], 'location_id' => ['nullable', 'exists:locations,id'], 'active' => ['required', 'boolean'], 'notes' => ['nullable', 'string']];
    }
}
