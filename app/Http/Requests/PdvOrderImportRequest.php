<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PdvOrderImportRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['confirmed' => $this->boolean('confirmed')]);
    }

    /** @return array<string,array<int,string>> */
    public function rules(): array
    {
        return ['confirmed' => ['required', 'accepted']];
    }
}
