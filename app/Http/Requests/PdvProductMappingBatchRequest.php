<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PdvProductMappingBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.selected' => ['nullable', 'boolean'],
            'rows.*.external_product_id' => ['required', 'string', 'max:255', 'distinct'],
            'rows.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('active', true)],
            'rows.*.confirm_remap' => ['nullable', 'boolean'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'confirmed' => ['sometimes', 'accepted'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $selected = collect($this->input('rows', []))->filter(fn (array $row): bool => (bool) ($row['selected'] ?? false));
            if ($selected->isEmpty()) {
                $validator->errors()->add('rows', 'Selecione ao menos um mapping para revisar.');
            }
            if ($selected->contains(fn (array $row): bool => empty($row['product_id']))) {
                $validator->errors()->add('rows', 'Todo mapping selecionado precisa de um produto ERP explícito.');
            }
        }];
    }

    /** @return array<int, array{external_product_id:string,product_id:int,confirm_remap:bool}> */
    public function selectedRows(): array
    {
        return collect($this->validated('rows'))
            ->filter(fn (array $row): bool => (bool) ($row['selected'] ?? false))
            ->map(fn (array $row): array => [
                'external_product_id' => (string) $row['external_product_id'],
                'product_id' => (int) $row['product_id'],
                'confirm_remap' => (bool) ($row['confirm_remap'] ?? false),
            ])->values()->all();
    }
}
