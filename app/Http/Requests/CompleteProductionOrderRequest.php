<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteProductionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['quantities' => ['required', 'array'], 'quantities.*' => ['required', 'decimal:0,6', 'gt:0']];
    }
}
