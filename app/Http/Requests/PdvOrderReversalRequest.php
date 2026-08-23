<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PdvOrderReversalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'confirmed' => ['required', 'accepted'],
            'confirmation_text' => ['required', Rule::in(['ESTORNAR'])],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
