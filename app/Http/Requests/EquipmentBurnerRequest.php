<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EquipmentBurnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['active' => $this->boolean('active')]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['simple', 'double', 'custom'])],
            'nominal_glp_consumption_kg_hour' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
            'power' => ['nullable', 'numeric', 'gt:0', 'decimal:0,4', 'required_with:power_unit'],
            'power_unit' => ['nullable', 'string', 'max:20', 'required_with:power'],
            'default_utilization_factor' => ['required', 'numeric', 'gt:0', 'lte:1', 'decimal:0,3'],
            'active' => ['required', 'boolean'],
        ];
    }
}
