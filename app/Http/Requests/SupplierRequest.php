<?php

namespace App\Http\Requests;

use App\Rules\Cnpj;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $document = preg_replace('/\D+/', '', (string) $this->input('document_number')) ?: null;
        $this->merge(['active' => $this->boolean('active'), 'document_type' => $document ? 'cnpj' : null, 'document_number' => $document]);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $documentRules = ['nullable', 'string', 'max:20', new Cnpj];
        if ($this->boolean('active')) {
            $documentRules[] = Rule::unique('suppliers', 'document_number')->where(fn ($query) => $query->where('document_type', 'cnpj')->where('active', true))->ignore($this->route('supplier'));
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'document_type' => ['nullable', Rule::in(['cnpj']), 'required_with:document_number'],
            'document_number' => $documentRules,
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['required', 'boolean'],
        ];
    }
}
