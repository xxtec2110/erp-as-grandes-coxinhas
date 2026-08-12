<?php

namespace App\Http\Requests;

use App\Models\Ingredient;
use App\Models\Preparation;
use App\Services\UnitConversionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PreparationIngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var Preparation $preparation */
        $preparation = $this->route('preparation');

        return [
            'ingredient_id' => [
                'required',
                'integer',
                'exists:ingredients,id',
                Rule::unique('preparation_ingredients', 'ingredient_id')
                    ->where('preparation_id', $preparation->id),
            ],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
            'unit' => ['required', Rule::in(['kg', 'g', 'l', 'ml', 'un'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['ingredient_id', 'unit'])) {
                return;
            }

            $ingredient = Ingredient::query()->find($this->integer('ingredient_id'));
            $conversion = app(UnitConversionService::class);

            if ($ingredient !== null && ! $conversion->areCompatible((string) $this->input('unit'), $ingredient->base_unit)) {
                $validator->errors()->add('unit', 'A unidade informada é incompatível com a unidade-base do insumo.');
            }
        }];
    }
}
