<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CUIT argentino uniforme: 11 dígitos con dígito verificador.
 * Acepta formatos con guiones/espacios; normaliza a 11 dígitos.
 */
class Cuit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $digits = self::normalize((string) $value);
        if ($digits === null || strlen($digits) !== 11) {
            $fail('El :attribute debe tener 11 dígitos.');

            return;
        }

        if (! self::isValidChecksum($digits)) {
            $fail('El :attribute no es un CUIT válido.');
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

    public static function isValidChecksum(string $digits): bool
    {
        if (strlen($digits) !== 11 || ! ctype_digit($digits)) {
            return false;
        }

        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int) $digits[$i] * $weights[$i];
        }
        $mod = $sum % 11;
        $check = 11 - $mod;
        if ($check === 11) {
            $check = 0;
        } elseif ($check === 10) {
            $check = 9;
        }

        return (int) $digits[10] === $check;
    }

    public static function format(?string $digits): ?string
    {
        $n = self::normalize($digits);
        if ($n === null || strlen($n) !== 11) {
            return $digits;
        }

        return substr($n, 0, 2).'-'.substr($n, 2, 8).'-'.substr($n, 10, 1);
    }
}
