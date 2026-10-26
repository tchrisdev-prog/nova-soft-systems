<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * El servidor trabaja en UTC, pero el negocio vive en Colombia. Un consultor
 * que registra horas a las 9 p. m. del lunes en Bogotá ya está en el martes
 * para UTC, así que "hoy" y "este mes" siempre se calculan aquí.
 */
final class BusinessCalendar
{
    public static function timezone(): string
    {
        return (string) config('nova.timezone', 'America/Bogota');
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    public static function parseDate(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, self::timezone());
    }
}
