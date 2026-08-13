<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Cnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if (strlen($digits) !== 14 || preg_match('/^(\d)\1{13}$/', $digits) || ! $this->validDigits($digits)) {
            $fail('Informe um CNPJ válido.');
        }
    }

    private function validDigits(string $digits): bool
    {
        $calculate = function (string $base, array $weights): int {
            $sum = 0;
            foreach ($weights as $index => $weight) {
                $sum += (int) $base[$index] * $weight;
            }
            $remainder = $sum % 11;

            return $remainder < 2 ? 0 : 11 - $remainder;
        };
        $first = $calculate(substr($digits, 0, 12), [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        $second = $calculate(substr($digits, 0, 12).$first, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

        return substr($digits, -2) === (string) $first.$second;
    }
}
