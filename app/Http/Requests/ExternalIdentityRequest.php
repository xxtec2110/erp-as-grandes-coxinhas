<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExternalIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['active', 'menu_enabled', 'structured_commands_allowed', 'free_chat_allowed', 'voice_allowed', 'image_allowed', 'document_allowed', 'reports_allowed', 'all_locations_access'] as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }
    }

    public function rules(): array
    {
        return ['display_name' => ['nullable', 'string', 'max:255'], 'user_id' => ['nullable', 'exists:users,id'], 'status' => ['required', Rule::in(['pending', 'approved', 'rejected', 'blocked', 'inactive'])], 'active' => ['required', 'boolean'], 'menu_enabled' => ['required', 'boolean'], 'structured_commands_allowed' => ['required', 'boolean'], 'free_chat_allowed' => ['required', 'boolean'], 'voice_allowed' => ['required', 'boolean'], 'image_allowed' => ['required', 'boolean'], 'document_allowed' => ['required', 'boolean'], 'reports_allowed' => ['required', 'boolean'], 'role_ids' => ['array'], 'role_ids.*' => ['exists:roles,id'], 'location_ids' => ['array'], 'location_ids.*' => ['exists:locations,id'], 'permission_overrides' => ['array'], 'permission_overrides.*' => ['in:inherit,allow,deny'], 'all_locations_access' => ['required', 'boolean']];
    }
}
