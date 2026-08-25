<?php

namespace App\Http\Requests;

use App\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWhatsAppBusinessPhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(AuthorizationService::class)->allows($this->user(), 'agent.whatsapp.manage_connection');
    }

    public function rules(): array
    {
        return [
            'business_phone' => ['required', 'string', 'max:30'],
            'confirm_business_phone' => ['accepted'],
        ];
    }
}
