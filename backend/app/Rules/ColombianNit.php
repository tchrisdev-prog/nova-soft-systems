<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * NIT colombiano con dígito de verificación, calculado con el método de la
 * DIAN (módulo 11 con pesos 3, 7, 13, 17...). Atrapa los errores de
 * digitación más comunes, como un número cambiado de lugar.
 *
 * Formato esperado: 900123456-8 (los puntos y espacios se quitan antes).
 */
class ColombianNit implements ValidationRule
{
    private const WEIGHTS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^(\d{6,15})-(\d)$/', $value, $parts)) {
            $fail('El NIT debe incluir el dígito de verificación, por ejemplo 900123456-8.');

            return;
        }

        if (self::checkDigit($parts[1]) !== (int) $parts[2]) {
            $fail('El dígito de verificación no corresponde al NIT. Revisa que el número esté bien escrito.');
        }
    }

    public static function checkDigit(string $number): int
    {
        $digits = array_reverse(str_split($number));
        $sum = 0;

        foreach ($digits as $i => $digit) {
            $sum += (int) $digit * self::WEIGHTS[$i];
        }

        $remainder = $sum % 11;

        return $remainder > 1 ? 11 - $remainder : $remainder;
    }

    /** Quita puntos, espacios y guiones de más: "900.123.456 - 7" → "900123456-8". */
    public static function normalize(string $value): string
    {
        $clean = preg_replace('/[\s.]/', '', $value) ?? '';

        return preg_replace('/-+/', '-', $clean) ?? $clean;
    }
}
