<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'period' => ['nullable', Rule::in(['today', 'week', 'fortnight', 'month', 'custom'])],
            'start_date' => ['nullable', 'required_if:period,custom', 'date'],
            'end_date' => ['nullable', 'required_if:period,custom', 'date', 'after_or_equal:start_date'],
        ];
    }

    /** @return array{string,string,string} */
    public function period(): array
    {
        return match ($this->validated('period', 'today')) {
            'week' => [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString(), 'Esta semana'],
            'fortnight' => [now()->subDays(13)->startOfDay()->toDateString(), now()->toDateString(), 'Últimos 14 dias'],
            'month' => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString(), 'Este mês'],
            'custom' => [$this->validated('start_date'), $this->validated('end_date'), 'Período personalizado'],
            default => [now()->toDateString(), now()->toDateString(), 'Hoje'],
        };
    }
}
