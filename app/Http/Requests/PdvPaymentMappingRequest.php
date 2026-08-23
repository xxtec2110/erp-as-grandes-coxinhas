<?php

namespace App\Http\Requests;

use App\Enums\ProductSalePaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PdvPaymentMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $configuration = (string) $this->input('financial_configuration');
        if (preg_match('/^(\d+):(\d+|none)$/', $configuration, $matches) === 1) {
            $this->merge(['acquirer_id' => $matches[1], 'card_brand_id' => $matches[2] === 'none' ? null : $matches[2]]);
        }
    }

    public function rules(): array
    {
        $requiresCard = fn (): bool => in_array($this->input('payment_method'), [ProductSalePaymentMethod::Debit->value, ProductSalePaymentMethod::Credit->value], true);

        return [
            'payment_method' => ['required', Rule::in(ProductSalePaymentMethod::values())],
            'financial_configuration' => ['nullable', 'string', 'max:100'],
            'acquirer_id' => ['nullable', Rule::requiredIf($requiresCard), Rule::prohibitedIf(fn (): bool => ! $requiresCard()), 'integer', Rule::exists('acquirers', 'id')->where('active', true)],
            'card_brand_id' => ['nullable', Rule::prohibitedIf(fn (): bool => ! $requiresCard()), 'integer', Rule::exists('card_brands', 'id')->where('active', true)],
            'confirm_remap' => ['sometimes', 'accepted'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
