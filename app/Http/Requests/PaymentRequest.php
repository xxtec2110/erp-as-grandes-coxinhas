<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['partner_advance' => $this->boolean('partner_advance')]);
    }

    public function rules(): array
    {
        return ['amount' => ['required', 'decimal:0,2', 'gt:0'], 'paid_at' => ['required', 'date'], 'financial_account_id' => ['required', 'exists:financial_accounts,id'], 'paid_by_user_id' => ['nullable', 'exists:users,id'], 'paid_by_name' => ['nullable', 'string', 'max:255'], 'payment_method' => ['required', 'string', 'max:30'], 'partner_advance' => ['required', 'boolean'], 'agent_attachment_id' => ['nullable', 'exists:agent_attachments,id'], 'notes' => ['nullable', 'string'], 'idempotency_key' => ['required', 'uuid']];
    }
}
