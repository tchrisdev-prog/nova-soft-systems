<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumOptions;

/**
 * Estado que define el líder. El "riesgo" no es un estado: se calcula con las
 * horas consumidas y las fechas, para que nunca quede desactualizado.
 */
enum ProjectStatus: string
{
    use EnumOptions;

    case Planned = 'planned';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planeado',
            self::Active => 'En curso',
            self::OnHold => 'Pausado',
            self::Closed => 'Cerrado',
        };
    }

    /**
     * Cambios de estado permitidos. Un proyecto cerrado no se reabre: si el
     * cliente pide más trabajo, se abre otro proyecto y cada uno conserva sus
     * cifras. Un proyecto planeado se puede cerrar si nunca arrancó.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Planned => [self::Active, self::Closed],
            self::Active => [self::OnHold, self::Closed],
            self::OnHold => [self::Active, self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Solo se registran horas en proyectos que están en curso. */
    public function acceptsTime(): bool
    {
        return $this === self::Active;
    }
}
