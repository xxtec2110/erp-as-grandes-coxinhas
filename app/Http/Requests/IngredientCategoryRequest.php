<?php

namespace App\Http\Requests;

use App\Models\IngredientCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IngredientCategoryRequest extends FormRequest
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
        /** @var IngredientCategory|null $category */
        $category = $this->route('ingredientCategory');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('ingredient_categories', 'name')->ignore($category),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['required', 'boolean'],
        ];
    }
}
