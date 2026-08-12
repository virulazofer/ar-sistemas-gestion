<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validación Luhn para número de tarjeta (solo dígitos; no se persiste el PAN).
 */
class Luhn implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if (strlen($digits) < 13 || strlen($digits) > 19 || ! ctype_digit($digits)) {
            $fail('El número de tarjeta no es válido.');

            return;
        }

        if (! self::passes($digits)) {
            $fail('El número de tarjeta no supera la verificación Luhn.');
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

    public static function last4(?string $value): ?string
    {
        $digits = self::normalize($value);
        if ($digits === null || strlen($digits) < 4) {
            return null;
        }

        return substr($digits, -4);
    }

    public static function formatDisplay(?string $value): string
    {
        $digits = self::normalize($value);
        if ($digits === null) {
            return '';
        }

        return trim(chunk_split($digits, 4, ' '));
    }

    public static function passes(string $digits): bool
    {
        if ($digits === '' || ! ctype_digit($digits)) {
            return false;
        }

        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }
}
