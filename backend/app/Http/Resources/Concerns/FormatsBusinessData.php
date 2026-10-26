<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use BackedEnum;
use Illuminate\Http\Request;

/**
 * Formatos comunes de la API del módulo de proyectos:
 * - Enums como { value, label } para que la interfaz no repita etiquetas.
 * - Fechas de calendario como "AAAA-MM-DD" (sin hora ni zona horaria).
 * - Saber si quien consulta puede ver costos internos.
 */
trait FormatsBusinessData
{
    /** @return array{value: string, label: string}|null */
    protected function enumValue(?BackedEnum $enum): ?array
    {
        return $enum ? ['value' => $enum->value, 'label' => $enum->label()] : null;
    }

    protected function canSeeFinancials(Request $request): bool
    {
        return (bool) $request->user()?->hasPermission('projects.financials');
    }
}
