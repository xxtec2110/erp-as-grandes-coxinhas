<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PdvProductOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pdv_connection_id' => ['nullable', 'required_with:external_product_id', 'integer', 'exists:pdv_connections,id'],
            'external_product_id' => ['nullable', 'required_with:pdv_connection_id', 'string', 'max:255'],
            'onboarding_from' => ['nullable', 'required_with:pdv_connection_id', 'date_format:Y-m-d'],
            'onboarding_to' => ['nullable', 'required_with:pdv_connection_id', 'date_format:Y-m-d', 'after_or_equal:onboarding_from'],
        ];
    }
}
