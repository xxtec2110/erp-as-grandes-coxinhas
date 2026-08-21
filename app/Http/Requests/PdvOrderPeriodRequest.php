<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PdvOrderPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'nullable';

        return [
            'from' => [$required, 'required_with:to', 'date_format:Y-m-d'],
            'to' => [$required, 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled(['from', 'to'])) {
                return;
            }
            $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('from'));
            $to = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('to'));
            if ($from->diffInDays($to) > 6) {
                $validator->errors()->add('to', 'O período máximo para preparar pedidos é de 7 dias.');
            }
        }];
    }
}
