<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use LogicException;

/**
 * Para registros que son historia: una vez escritos no se modifican ni se
 * borran desde la aplicación. Si algo estaba mal, se registra una corrección.
 */
trait Immutable
{
    protected static function bootImmutable(): void
    {
        static::updating(function (): never {
            throw new LogicException(static::class.' es un registro histórico y no se puede modificar.');
        });

        static::deleting(function (): never {
            throw new LogicException(static::class.' es un registro histórico y no se puede eliminar.');
        });
    }
}
