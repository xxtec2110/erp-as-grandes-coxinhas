<?php

namespace App\Http\Requests;

use App\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceExternalIdentityPhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(AuthorizationService::class)->allows($this->user(), 'whatsapp.identities.manage');
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:30'],
            'confirm_replace' => ['accepted'],
        ];
    }
}
