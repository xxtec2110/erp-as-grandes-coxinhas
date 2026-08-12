<?php

namespace App\Http\Requests;

use App\Models\PaymentFee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['payment_method' => $this->input('payment_method', 'cash')]);
    }

    public function rules(): array
    {
        return ['location_id' => ['required', Rule::exists('locations', 'id')->where('active', true)], 'product_id' => ['required', Rule::exists('products', 'id')->where('active', true)], 'quantity' => ['required', 'decimal:0,6', 'gt:0'], 'unit_price' => ['required', 'decimal:0,4', 'gte:0'], 'payment_method' => ['required', Rule::in(['cash', PaymentFee::METHOD_DEBIT, PaymentFee::METHOD_CREDIT])], 'acquirer_id' => ['nullable', 'required_unless:payment_method,cash', 'exists:acquirers,id'], 'card_brand_id' => ['nullable', 'required_unless:payment_method,cash', 'exists:card_brands,id'], 'installments' => ['nullable', 'integer', 'min:1', 'max:99'], 'operation_date' => ['required', 'date'], 'idempotency_key' => ['required', 'uuid'], 'notes' => ['nullable', 'string', 'max:2000']];
    }
}
