<?php

namespace App\Http\Requests;

use App\Services\IngredientSemanticResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IngredientRequest extends FormRequest
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
            'ingredient_category_id' => ['nullable', 'integer', 'exists:ingredient_categories,id'],
            'brand' => ['nullable', 'string', 'max:255'],
            'base_unit' => ['required', Rule::in(['g', 'ml', 'un'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (app(IngredientSemanticResolver::class)->isProtectedBusinessTerm((string) $this->input('name'))) {
                $validator->errors()->add('name', 'Use Requeijão como nome do insumo. “Catupiry” é um termo comercial protegido da receita, não um estoque independente.');
            }
        }];
    }
}
