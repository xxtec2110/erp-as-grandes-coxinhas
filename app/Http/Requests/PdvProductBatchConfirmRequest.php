<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PdvProductBatchConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['confirmed' => $this->boolean('confirmed')]);
    }

    /** @return array<string,array<int,string>> */
    public function rules(): array
    {
        return [
            'preview_token' => ['required', 'string'],
            'confirmed' => ['required', 'accepted'],
            'confirmation_text' => ['required', 'string', 'in:CRIAR PRODUTOS'],
        ];
    }
}
