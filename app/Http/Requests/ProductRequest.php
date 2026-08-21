<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $prepared = ['active' => $this->boolean('active')];
        if ($this->has('aliases_text')) {
            $prepared['aliases'] = collect(preg_split('/\R/u', (string) $this->input('aliases_text')) ?: [])
                ->map(fn (string $alias) => trim($alias))
                ->filter()
                ->values()
                ->all();
        }
        if ($this->filled('selling_price')) {
            $value = trim((string) $this->input('selling_price'));
            $prepared['selling_price'] = str_contains($value, ',')
                ? str_replace(['.', ','], ['', '.'], $value)
                : $value;
        }
        $this->merge($prepared);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'product_category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'stock_unit' => ['required', Rule::in([Product::UNIT_COUNT, Product::UNIT_GRAM, Product::UNIT_MILLILITER])],
            'sort_order' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'selling_price' => ['nullable', 'decimal:0,4', 'gt:0'],
            'active' => ['required', 'boolean'],
            'aliases' => ['sometimes', 'array', 'max:20'],
            'aliases.*' => ['required', 'string', 'max:255'],
            'pdv_connection_id' => ['nullable', 'required_with:external_product_id', 'integer', 'exists:pdv_connections,id'],
            'external_product_id' => ['nullable', 'required_with:pdv_connection_id', 'string', 'max:255'],
            'onboarding_from' => ['nullable', 'required_with:pdv_connection_id', 'date_format:Y-m-d'],
            'onboarding_to' => ['nullable', 'required_with:pdv_connection_id', 'date_format:Y-m-d', 'after_or_equal:onboarding_from'],
        ];
    }
}
