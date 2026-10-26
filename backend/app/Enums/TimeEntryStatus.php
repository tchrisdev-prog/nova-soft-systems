<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumOptions;

/**
 * Ciclo de vida de un registro de horas:
 * borrador → enviado → aprobado | rechazado → (corregido) → enviado...
 */
enum TimeEntryStatus: string
{
    use EnumOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Submitted => 'Enviado',
            self::Approved => 'Aprobado',
            self::Rejected => 'Rechazado',
        };
    }

    /** El consultor solo puede tocar lo que todavía no está en revisión. */
    public function isEditableByOwner(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }
}
