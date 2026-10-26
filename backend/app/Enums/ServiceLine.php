<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumOptions;

/** Qué vende Nexus Safe-Cloud. Cada contrato pertenece a una línea de servicio. */
enum ServiceLine: string
{
    use EnumOptions;

    case CustomSoftware = 'custom_software';
    case InfrastructureSupport = 'infrastructure_support';
    case CloudServices = 'cloud_services';
    case Cybersecurity = 'cybersecurity';

    public function label(): string
    {
        return match ($this) {
            self::CustomSoftware => 'Software a la medida',
            self::InfrastructureSupport => 'Soporte de infraestructura',
            self::CloudServices => 'Servicios de nube',
            self::Cybersecurity => 'Consultoría de ciberseguridad',
        };
    }
}
