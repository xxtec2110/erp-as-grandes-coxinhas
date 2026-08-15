<?php

namespace App\Http\Requests;

use App\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

class StoreExternalIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(AuthorizationService::class)->allows($this->user(), 'whatsapp.identities.manage');
    }

    protected function prepareForValidation(): void
    {
        foreach (['voice_allowed', 'image_allowed', 'document_allowed', 'reports_allowed'] as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'], 'phone' => ['required', 'string', 'max:30'],
            'confirm_authorization' => ['accepted'],
            'voice_allowed' => ['boolean'], 'image_allowed' => ['boolean'], 'document_allowed' => ['boolean'], 'reports_allowed' => ['boolean'],
        ];
    }
}
