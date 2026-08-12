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
        $this->merge(['active' => $this->boolean('active')]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'product_category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'stock_unit' => ['required', Rule::in([Product::UNIT_COUNT, Product::UNIT_GRAM, Product::UNIT_MILLILITER])],
            'active' => ['required', 'boolean'],
        ];
    }
}
