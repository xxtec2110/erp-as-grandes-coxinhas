<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PdvProductBatchOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rows = collect($this->input('rows', []))->map(function (mixed $row): mixed {
            if (! is_array($row)) {
                return $row;
            }
            $row['selected'] = filter_var($row['selected'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $row['active'] = filter_var($row['active'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (isset($row['selling_price'])) {
                $value = trim((string) $row['selling_price']);
                $row['selling_price'] = str_contains($value, ',')
                    ? str_replace(['.', ','], ['', '.'], $value)
                    : $value;
            }

            return $row;
        })->all();

        $this->merge(['rows' => $rows]);
    }

    /** @return array<string,array<int,mixed>> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'rows' => ['required', 'array', 'max:25'],
            'rows.*.selected' => ['nullable', 'boolean'],
            'rows.*.external_product_id' => ['required', 'string', 'max:255'],
            'rows.*.name' => ['required_if:rows.*.selected,true', 'nullable', 'string', 'max:255'],
            'rows.*.product_category_id' => ['required_if:rows.*.selected,true', 'nullable', 'integer', 'exists:product_categories,id'],
            'rows.*.selling_price' => ['required_if:rows.*.selected,true', 'nullable', 'decimal:0,4', 'gt:0'],
            'rows.*.active' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->selectedRows() === []) {
                $validator->errors()->add('rows', 'Selecione pelo menos um produto para preparar a prévia.');
            }
        }];
    }

    /** @return array<int,array<string,mixed>> */
    public function selectedRows(): array
    {
        return collect($this->input('rows', []))
            ->filter(fn (mixed $row): bool => is_array($row) && (bool) ($row['selected'] ?? false))
            ->values()->all();
    }
}
