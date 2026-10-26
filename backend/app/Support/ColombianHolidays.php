<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Festivos de Colombia, calculados (no escritos a mano año por año):
 *
 * - Fijos: 1 de enero, 1 de mayo, 20 de julio, 7 de agosto, 8 y 25 de diciembre.
 * - Ley Emiliani (Ley 51 de 1983): Reyes, San José, San Pedro y San Pablo,
 *   Asunción, Día de la Raza, Todos los Santos e Independencia de Cartagena
 *   se trasladan al lunes siguiente si no caen en lunes.
 * - Según la Semana Santa: Jueves y Viernes Santo (no se trasladan) y
 *   Ascensión, Corpus Christi y Sagrado Corazón (se trasladan a lunes).
 *
 * Verificado contra el calendario oficial de 2026 (18 festivos).
 */
final class ColombianHolidays
{
    /** @var array<int, array<string, string>> caché por año: fecha => nombre */
    private static array $cache = [];

    /** @return array<string, string> fecha (AAAA-MM-DD) => nombre */
    public static function forYear(int $year): array
    {
        return self::$cache[$year] ??= self::build($year);
    }

    public static function isHoliday(CarbonImmutable $day): bool
    {
        return isset(self::forYear($day->year)[$day->toDateString()]);
    }

    public static function name(CarbonImmutable $day): ?string
    {
        return self::forYear($day->year)[$day->toDateString()] ?? null;
    }

    /** @return array<string, string> */
    private static function build(int $year): array
    {
        $date = fn (int $month, int $day) => CarbonImmutable::create($year, $month, $day, 0, 0, 0, BusinessCalendar::timezone());
        $monday = fn (CarbonImmutable $d) => $d->isMonday() ? $d : $d->next(CarbonImmutable::MONDAY);
        $easter = self::easterSunday($year);

        $holidays = [
            [$date(1, 1), 'Año Nuevo'],
            [$monday($date(1, 6)), 'Día de los Reyes Magos'],
            [$monday($date(3, 19)), 'Día de San José'],
            [$easter->subDays(3), 'Jueves Santo'],
            [$easter->subDays(2), 'Viernes Santo'],
            [$date(5, 1), 'Día del Trabajo'],
            [$monday($easter->addDays(39)), 'Ascensión del Señor'],
            [$monday($easter->addDays(60)), 'Corpus Christi'],
            [$monday($easter->addDays(68)), 'Sagrado Corazón'],
            [$monday($date(6, 29)), 'San Pedro y San Pablo'],
            [$date(7, 20), 'Día de la Independencia'],
            [$date(8, 7), 'Batalla de Boyacá'],
            [$monday($date(8, 15)), 'Asunción de la Virgen'],
            [$monday($date(10, 12)), 'Día de la Raza'],
            [$monday($date(11, 1)), 'Día de Todos los Santos'],
            [$monday($date(11, 11)), 'Independencia de Cartagena'],
            [$date(12, 8), 'Inmaculada Concepción'],
            [$date(12, 25), 'Navidad'],
        ];

        $result = [];
        foreach ($holidays as [$day, $name]) {
            $result[$day->toDateString()] = $name;
        }
        ksort($result);

        return $result;
    }

    /** Domingo de Pascua (algoritmo gregoriano de Meeus/Butcher). */
    private static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, BusinessCalendar::timezone());
    }
}
