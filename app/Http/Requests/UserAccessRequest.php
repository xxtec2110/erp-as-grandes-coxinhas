<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['all_locations_access' => $this->boolean('all_locations_access')]);
    }

    public function rules(): array
    {
        return ['role_ids' => ['array'], 'role_ids.*' => ['integer', 'exists:roles,id'], 'location_ids' => ['array'], 'location_ids.*' => ['integer', 'exists:locations,id'], 'permission_overrides' => ['array'], 'permission_overrides.*' => ['in:inherit,allow,deny'], 'all_locations_access' => ['required', 'boolean']];
    }
}
