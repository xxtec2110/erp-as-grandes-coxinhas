<?php

namespace App\Http\Requests;

use App\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExternalIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(AuthorizationService::class)->allows($this->user(), 'whatsapp.identities.manage');
    }

    protected function prepareForValidation(): void
    {
        foreach (['active', 'menu_enabled', 'structured_commands_allowed', 'free_chat_allowed', 'voice_allowed', 'image_allowed', 'document_allowed', 'reports_allowed'] as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }
        if ($this->has('respond_enabled')) {
            $this->merge(['respond_enabled' => $this->boolean('respond_enabled')]);
        }
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in(['approved', 'blocked', 'inactive'])],
            'active' => ['required', 'boolean'], 'respond_enabled' => ['sometimes', 'required', 'boolean'], 'menu_enabled' => ['required', 'boolean'],
            'structured_commands_allowed' => ['required', 'boolean'], 'free_chat_allowed' => ['required', 'boolean'],
            'voice_allowed' => ['required', 'boolean'], 'image_allowed' => ['required', 'boolean'],
            'document_allowed' => ['required', 'boolean'], 'reports_allowed' => ['required', 'boolean'],
            'confirm_user_change' => (int) $this->input('user_id', $this->route('identity')?->user_id) !== (int) $this->route('identity')?->user_id
                ? ['accepted']
                : ['nullable'],
        ];
    }
}
