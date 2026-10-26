<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumOptions;

/**
 * Especialidad del consultor. Es lo que pregunta Comercial cuando vende:
 * "¿hay alguien de ciberseguridad libre?". El cargo es texto libre; la
 * especialidad es fija para poder filtrar y sumar capacidad.
 */
enum StaffSpecialty: string
{
    use EnumOptions;

    case Development = 'development';
    case Qa = 'qa';
    case Infrastructure = 'infrastructure';
    case Cloud = 'cloud';
    case Cybersecurity = 'cybersecurity';
    case Management = 'management';
    case Commercial = 'commercial';

    public function label(): string
    {
        return match ($this) {
            self::Development => 'Desarrollo',
            self::Qa => 'Calidad (QA)',
            self::Infrastructure => 'Infraestructura',
            self::Cloud => 'Nube y DevOps',
            self::Cybersecurity => 'Ciberseguridad',
            self::Management => 'Gestión de proyectos',
            self::Commercial => 'Comercial',
        };
    }
}
