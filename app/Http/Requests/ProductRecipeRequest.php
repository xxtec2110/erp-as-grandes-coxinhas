<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['ingredients' => array_values(array_filter($this->input('ingredients', []), fn (array $item) => filled($item['quantity'] ?? null))), 'preparations' => array_values(array_filter($this->input('preparations', []), fn (array $item) => filled($item['quantity'] ?? null)))]);
    }

    public function rules(): array
    {
        return ['final_weight_grams' => ['nullable', 'decimal:0,6', 'gt:0'], 'yield_quantity' => ['required', 'decimal:0,6', 'gt:0'], 'technical_loss_percentage' => ['required', 'decimal:0,6', 'gte:0', 'lt:100'], 'packaging_cost' => ['required', 'decimal:0,6', 'gte:0'], 'selling_price' => ['nullable', 'decimal:0,4', 'gt:0'], 'notes' => ['nullable', 'string'], 'ingredients' => ['array'], 'ingredients.*.ingredient_id' => ['required', 'exists:ingredients,id'], 'ingredients.*.quantity' => ['required', 'decimal:0,6', 'gt:0'], 'ingredients.*.unit' => ['required', 'in:kg,g,l,ml,un'], 'preparations' => ['array'], 'preparations.*.preparation_id' => ['required', 'exists:preparations,id'], 'preparations.*.quantity' => ['required', 'decimal:0,6', 'gt:0'], 'preparations.*.unit' => ['required', 'in:kg,g,l,ml,un']];
    }
}
