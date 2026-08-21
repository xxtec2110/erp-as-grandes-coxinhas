<?php

namespace App\Http\Requests;

use App\Enums\ProductSalePaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'payment_method' => ['nullable', 'required_if:mapping_type,payment', Rule::in(ProductSalePaymentMethod::values())],
            'acquirer_id' => ['nullable', 'exists:acquirers,id'],
            'card_brand_id' => ['nullable', 'exists:card_brands,id'],
            'confirm_remap' => ['sometimes', 'accepted'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'required_unless:mapping_type,location', 'uuid'],
        ];
    }
}
