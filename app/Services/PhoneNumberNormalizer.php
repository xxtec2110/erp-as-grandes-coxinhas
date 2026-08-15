<?php

namespace App\Services;

use DomainException;

class PhoneNumberNormalizer
{
    public function normalize(string $value, string $defaultCountryCode = '55'): string
    {
        $digits = preg_replace('/\D+/', '', trim($value)) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (! str_starts_with($digits, $defaultCountryCode) && strlen($digits) >= 10 && strlen($digits) <= 11) {
            $digits = $defaultCountryCode.$digits;
        }
        if (strlen($digits) < 12 || strlen($digits) > 15) {
            throw new DomainException('Informe um telefone válido com DDD.');
        }

        return '+'.$digits;
    }

    public function providerIdentifier(string $normalized): string
    {
        return ltrim($normalized, '+');
    }

    public function mask(?string $normalized): string
    {
        if (blank($normalized)) {
            return '—';
        }
        $digits = ltrim($normalized, '+');

        return '+'.substr($digits, 0, 4).' ••••• '.substr($digits, -4);
    }
}
