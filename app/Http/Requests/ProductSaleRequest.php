<?php

namespace App\Http\Requests;

use App\Enums\ProductSalePaymentMethod;
use App\Models\Location;
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
        $requiresCard = fn (): bool => in_array($this->input('payment_method'), [ProductSalePaymentMethod::Debit->value, ProductSalePaymentMethod::Credit->value], true);

        return ['location_id' => ['required', Rule::exists('locations', 'id')->where(fn ($query) => $query->where('active', true)->where('type', Location::TYPE_STORE))], 'product_id' => ['required', Rule::exists('products', 'id')->where('active', true)], 'quantity' => ['required', 'decimal:0,6', 'gt:0'], 'unit_price' => ['required', 'decimal:0,4', 'gte:0'], 'payment_method' => ['required', Rule::in(ProductSalePaymentMethod::values())], 'acquirer_id' => ['nullable', Rule::requiredIf($requiresCard), Rule::prohibitedIf(fn (): bool => ! $requiresCard()), 'exists:acquirers,id'], 'card_brand_id' => ['nullable', Rule::requiredIf($requiresCard), Rule::prohibitedIf(fn (): bool => ! $requiresCard()), 'exists:card_brands,id'], 'installments' => ['nullable', Rule::prohibitedIf(fn (): bool => ! $requiresCard()), 'integer', 'min:1', 'max:99'], 'operation_date' => ['required', 'date'], 'idempotency_key' => ['required', 'uuid'], 'notes' => ['nullable', 'string', 'max:2000']];
    }
}
