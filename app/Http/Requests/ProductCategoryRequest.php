<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductCategoryRequest extends FormRequest
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
        return ['name' => ['required', 'string', 'max:255', Rule::unique('product_categories')->ignore($this->route('productCategory'))], 'active' => ['required', 'boolean']];
    }
}
