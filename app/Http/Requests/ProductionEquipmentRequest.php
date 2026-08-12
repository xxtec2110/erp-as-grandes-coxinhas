<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductionEquipmentRequest extends FormRequest
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
            'type' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'energy_source' => ['required', Rule::in(['glp', 'electric', 'other'])],
            'nominal_glp_consumption_kg_hour' => ['nullable', 'numeric', 'gt:0', 'decimal:0,6'],
            'power' => ['nullable', 'numeric', 'gt:0', 'decimal:0,4', 'required_with:power_unit'],
            'power_unit' => ['nullable', 'string', 'max:20', 'required_with:power'],
            'default_utilization_factor' => ['required', 'numeric', 'gt:0', 'lte:1', 'decimal:0,3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['required', 'boolean'],
        ];
    }
}
