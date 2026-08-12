<?php

namespace App\Http\Requests;

use App\Models\Ingredient;
use App\Services\UnitConversionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IngredientPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_current' => $this->boolean('is_current')]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var Ingredient $ingredient */
        $ingredient = $this->route('ingredient');
        $allowedUnits = app(UnitConversionService::class)->allowedPurchaseUnits($ingredient->base_unit);

        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'purchase_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'purchase_unit' => ['required', Rule::in($allowedUnits)],
            'price_paid' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
            'effective_date' => ['required', 'date'],
            'is_current' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'purchase_unit.in' => 'A unidade da compra é incompatível com a unidade-base do insumo.',
        ];
    }
}
