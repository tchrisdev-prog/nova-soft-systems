<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumOptions;

/**
 * Cómo se cobra un contrato.
 *
 * - Bolsa de horas: el cliente compra un paquete de horas que se va
 *   consumiendo y que se puede recargar.
 * - Mensualidad recurrente: un valor fijo cada mes, con o sin horas incluidas.
 */
enum BillingModel: string
{
    use EnumOptions;

    case HourBag = 'hour_bag';
    case MonthlyRecurring = 'monthly_recurring';

    public function label(): string
    {
        return match ($this) {
            self::HourBag => 'Bolsa de horas',
            self::MonthlyRecurring => 'Mensualidad recurrente',
        };
    }
}
