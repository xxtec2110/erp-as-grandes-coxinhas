<?php

namespace App\Http\Requests;

use App\Services\DashboardWidgetRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardWidgetVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'widgets' => ['required', 'array'],
            'widgets.*' => ['required', Rule::in(['inherit', 'show', 'hide'])],
        ];
    }

    protected function passedValidation(): void
    {
        $unknown = array_diff(array_keys($this->validated('widgets', [])), app(DashboardWidgetRegistry::class)->keys());
        abort_if($unknown !== [], 422, 'Widget de dashboard desconhecido.');
    }
}
