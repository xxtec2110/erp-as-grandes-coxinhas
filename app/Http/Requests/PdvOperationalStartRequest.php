<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PdvOperationalStartRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['confirmed' => $this->boolean('confirmed')]);
    }

    /** @return array<string,array<int,string>> */
    public function rules(): array
    {
        return [
            'operational_start_date' => ['required', 'date_format:Y-m-d'],
            'operational_start_time' => ['required', 'date_format:H:i'],
            'confirmed' => ['required', 'accepted'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
