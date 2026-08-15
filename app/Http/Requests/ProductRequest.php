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
        $this->merge($prepared);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'product_category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'stock_unit' => ['required', Rule::in([Product::UNIT_COUNT, Product::UNIT_GRAM, Product::UNIT_MILLILITER])],
            'active' => ['required', 'boolean'],
            'aliases' => ['sometimes', 'array', 'max:20'],
            'aliases.*' => ['required', 'string', 'max:255'],
        ];
    }
}
