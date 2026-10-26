<?php

declare(strict_types=1);

namespace App\Casts;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fecha de calendario (sin hora): fechas de contratos, días trabajados...
 *
 * Laravel guarda los campos "date" con hora ("2026-10-31 00:00:00"). En
 * PostgreSQL da igual porque la columna es de tipo fecha, pero en SQLite se
 * guarda como texto y entonces "2026-10-31 00:00:00" <= "2026-10-31" es
 * falso: el último día del mes quedaba fuera de los consumos. Guardando
 * siempre "AAAA-MM-DD" las comparaciones funcionan igual en ambas bases.
 *
 * @implements CastsAttributes<Carbon, string>
 */
class CalendarDate implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::createFromFormat('!Y-m-d', substr((string) $value, 0, 10));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof CarbonInterface
            ? $value->format('Y-m-d')
            : substr((string) $value, 0, 10);
    }
}
