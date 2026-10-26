<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumOptions;

/** Horario en que el contrato promete atención al cliente. */
enum Coverage: string
{
    use EnumOptions;

    case BusinessHours = 'business_hours';
    case Extended = 'extended';
    case AllDay = '24x7';

    public function label(): string
    {
        return match ($this) {
            self::BusinessHours => 'Horario hábil (8×5)',
            self::Extended => 'Extendido',
            self::AllDay => '24/7',
        };
    }
}
