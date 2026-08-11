<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CBU/CVU argentino: exactamente 22 dígitos (sin guiones ni espacios al validar).
 */
class CbuCvu implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if (strlen($digits) !== 22) {
            $fail('El :attribute debe tener exactamente 22 dígitos (CBU/CVU).');
        }
    }

    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits !== '' ? $digits : null;
    }
}
