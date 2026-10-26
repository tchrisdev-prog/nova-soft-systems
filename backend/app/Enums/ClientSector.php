<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumOptions;

enum ClientSector: string
{
    use EnumOptions;

    case Financial = 'financial';
    case Retail = 'retail';
    case Sme = 'sme';
    case Public = 'public';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Financial => 'Financiero',
            self::Retail => 'Retail',
            self::Sme => 'Pyme',
            self::Public => 'Sector público',
            self::Other => 'Otro',
        };
    }
}
