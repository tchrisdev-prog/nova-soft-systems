<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumOptions;

enum ContractStatus: string
{
    use EnumOptions;

    case Draft = 'draft';
    case Active = 'active';
    case Suspended = 'suspended';
    case Finished = 'finished';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Active => 'Activo',
            self::Suspended => 'Suspendido',
            self::Finished => 'Finalizado',
            self::Cancelled => 'Cancelado',
        };
    }

    /**
     * Cambios de estado permitidos. Un contrato finalizado o cancelado ya no
     * vuelve a abrirse: si el cliente quiere seguir, se firma uno nuevo y así
     * el historial de cada contrato queda limpio.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Cancelled],
            self::Active => [self::Suspended, self::Finished, self::Cancelled],
            self::Suspended => [self::Active, self::Finished, self::Cancelled],
            self::Finished, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
