<?php

namespace App\Enums;

enum ModuleStatus: string
{
    case Active = 'active';
    case Planned = 'planned';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::Planned => 'Pendiente de desarrollo',
            self::Inactive => 'Inactivo',
        };
    }
}
