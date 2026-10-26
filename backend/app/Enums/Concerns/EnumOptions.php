<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * Utilidades compartidas por los Enums del negocio: la lista de valores para
 * validar y las opciones (valor + etiqueta) que consume el frontend, así las
 * etiquetas viven en un solo lugar.
 */
trait EnumOptions
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
