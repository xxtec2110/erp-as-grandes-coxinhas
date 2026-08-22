<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PdvOrderImportRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'confirmed' => $this->boolean('confirmed'),
            'single_order_confirmed' => $this->boolean('single_order_confirmed'),
        ]);
    }

    /** @return array<string,array<int,string>> */
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
            'single_order_confirmed' => ['required', 'accepted'],
            'confirmation_text' => ['required', 'string', 'in:IMPORTAR'],
        ];
    }
}
