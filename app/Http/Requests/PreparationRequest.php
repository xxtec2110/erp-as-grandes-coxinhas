<?php

namespace App\Http\Requests;

use App\Services\UnitConversionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PreparationRequest extends FormRequest
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
        $units = ['kg', 'g', 'l', 'ml', 'un'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'initial_quantity' => ['nullable', 'numeric', 'gt:0', 'decimal:0,6', 'required_with:initial_unit'],
            'initial_unit' => ['nullable', Rule::in($units), 'required_with:initial_quantity'],
            'expected_yield' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
            'yield_unit' => ['required', Rule::in($units)],
            'actual_final_quantity' => ['nullable', 'numeric', 'gt:0', 'decimal:0,6'],
            'total_preparation_time_minutes' => ['required', 'integer', 'min:1', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('actual_final_quantity') && $this->filled('initial_quantity')) {
                $conversion = app(UnitConversionService::class);

                if (! $conversion->areCompatible((string) $this->input('initial_unit'), (string) $this->input('yield_unit'))) {
                    $validator->errors()->add(
                        'actual_final_quantity',
                        'A quantidade inicial e a quantidade final devem usar unidades compatíveis.',
                    );
                }
            }
        }];
    }
}
