<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumOptions;

/**
 * Movimientos de una bolsa de horas. La bolsa nunca se edita: cada compra o
 * corrección queda como una línea nueva, como en un extracto bancario.
 */
enum HourMovementType: string
{
    use EnumOptions;

    case Initial = 'initial';
    case TopUp = 'top_up';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Bolsa inicial',
            self::TopUp => 'Recarga',
            self::Adjustment => 'Ajuste',
        };
    }
}
