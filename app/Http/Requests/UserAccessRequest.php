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
        if ($this->boolean('_manage_permissions')) {
            $this->merge(['role_ids' => $this->input('role_ids', [])]);
        }
        if ($this->boolean('_manage_locations')) {
            $this->merge([
                'location_ids' => $this->input('location_ids', []),
                'all_locations_access' => $this->boolean('all_locations_access'),
            ]);
        }
    }

    public function rules(): array
    {
        return ['role_ids' => ['sometimes', 'array'], 'role_ids.*' => ['integer', 'exists:roles,id'], 'location_ids' => ['sometimes', 'array'], 'location_ids.*' => ['integer', 'exists:locations,id'], 'default_location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'], 'permission_overrides' => ['sometimes', 'array'], 'permission_overrides.*' => ['in:inherit,allow,deny'], 'all_locations_access' => ['sometimes', 'boolean']];
    }
}
