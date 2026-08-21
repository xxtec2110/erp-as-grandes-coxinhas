<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PdvPaymentMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::in(['cash', 'debit', 'credit'])],
            'acquirer_id' => ['nullable', 'required_unless:payment_method,cash', 'integer', Rule::exists('acquirers', 'id')->where('active', true)],
            'card_brand_id' => ['nullable', 'required_unless:payment_method,cash', 'integer', Rule::exists('card_brands', 'id')->where('active', true)],
            'confirm_remap' => ['sometimes', 'accepted'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
