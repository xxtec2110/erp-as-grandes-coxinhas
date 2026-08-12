<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgentAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $maximum = max((int) config('attachments.max_image_mb'), (int) config('attachments.max_document_mb')) * 1024;

        return [
            'attachment' => ['required', 'file', 'max:'.$maximum, 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png'],
            'purpose' => ['required', Rule::in(['agent', 'purchase', 'finance'])],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'retention_type' => ['required', Rule::in(['temporary', 'official'])],
        ];
    }
}
