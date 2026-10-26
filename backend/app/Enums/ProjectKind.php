<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumOptions;

/**
 * Todo el trabajo cuelga de un proyecto. Un "servicio continuo" es el soporte
 * o la operación en la nube que no tiene fecha de fin y se renueva cada mes.
 */
enum ProjectKind: string
{
    use EnumOptions;

    case Project = 'project';
    case OngoingService = 'ongoing_service';

    public function label(): string
    {
        return match ($this) {
            self::Project => 'Proyecto',
            self::OngoingService => 'Servicio continuo',
        };
    }
}
