<?php

namespace App\Http\Requests;

use App\Models\LossReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LossReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['active' => $this->boolean('active')]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $reason = $this->route('lossReason');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('loss_reasons')->ignore($reason instanceof LossReason ? $reason->id : null)],
            'active' => ['required', 'boolean'],
        ];
    }
}
