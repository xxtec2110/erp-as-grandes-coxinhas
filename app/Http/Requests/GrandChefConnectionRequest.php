<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GrandChefConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['enabled' => $this->boolean('enabled')]);
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'name' => ['required', 'string', 'max:255'],
            'endpoint' => ['required', 'url:https', 'max:2048'],
            'bearer_token' => ['nullable', 'string', 'max:4096'],
            'device_token' => ['nullable', 'string', 'max:4096'],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
