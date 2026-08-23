<?php

namespace App\Http\Requests;

use App\Models\PaymentFee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentFeeBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['idempotency_key' => ['required', 'uuid'], 'acquirer_id' => ['required', 'exists:acquirers,id'], 'effective_from' => ['required', 'date'], 'rows' => ['required', 'array', 'min:1'], 'rows.*.card_brand_id' => ['nullable', 'exists:card_brands,id'], 'rows.*.payment_method' => ['required', Rule::in([PaymentFee::METHOD_DEBIT, PaymentFee::METHOD_CREDIT])], 'rows.*.installments' => ['nullable', 'integer', 'min:1', 'max:99'], 'rows.*.fee_percentage' => ['required', 'decimal:0,6', 'gte:0', 'lte:100'], 'rows.*.fixed_fee' => ['nullable', 'decimal:0,4', 'gte:0'], 'rows.*.notes' => ['nullable', 'string', 'max:1000']];
    }
}
