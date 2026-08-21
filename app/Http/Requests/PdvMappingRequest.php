<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PdvMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mapping_type' => ['required', 'in:location,product,payment'],
            'mapping_id' => ['required', 'integer'],
            'target_id' => ['nullable', 'required_unless:mapping_type,payment', 'integer'],
            'payment_method' => ['nullable', 'required_if:mapping_type,payment', 'in:cash,debit,credit,pix,voucher'],
            'acquirer_id' => ['nullable', 'exists:acquirers,id'],
            'card_brand_id' => ['nullable', 'exists:card_brands,id'],
        ];
    }
}
