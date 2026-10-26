<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Violación de una regla de negocio (ejemplo: retirar el último Super Admin).
 * Se convierte en una respuesta JSON con un código estable que el frontend
 * puede interpretar.
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function lastSuperAdmin(): self
    {
        return new self(
            'La operación dejaría el sistema sin un Super Admin activo. Asigna el rol a otro usuario activo antes de continuar.',
            'last_super_admin',
            409,
        );
    }

    public static function protectedRole(): self
    {
        return new self('El rol Super Admin es un rol de sistema y no puede modificarse.', 'protected_role', 403);
    }

    public static function privilegeEscalation(string $detail): self
    {
        return new self($detail, 'privilege_escalation', 403);
    }

    public static function selfModification(): self
    {
        return new self('No puedes modificar tu propio estado ni tus propios roles desde esta operación.', 'self_modification', 403);
    }

    public static function inactiveRole(): self
    {
        return new self('No es posible asignar roles inactivos.', 'inactive_role', 422);
    }
}
