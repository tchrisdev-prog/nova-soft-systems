<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumOptions;

/** Para qué se le escribe a cada contacto de un cliente. */
enum ContactType: string
{
    use EnumOptions;

    case Commercial = 'commercial';
    case Technical = 'technical';
    case Billing = 'billing';
    case Executive = 'executive';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Commercial => 'Comercial',
            self::Technical => 'Técnico',
            self::Billing => 'Facturación',
            self::Executive => 'Directivo',
            self::Other => 'Otro',
        };
    }
}
