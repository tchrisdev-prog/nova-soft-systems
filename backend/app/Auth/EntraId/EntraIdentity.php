<?php

namespace App\Auth\EntraId;

/**
 * Identidad confirmada a partir de un id_token válido de Entra ID.
 */
final class EntraIdentity
{
    public function __construct(
        public readonly string $objectId,   // claim "oid": inmutable por usuario y tenant
        public readonly string $tenantId,   // claim "tid"
        public readonly string $email,      // claim "email" o "preferred_username" (UPN)
        public readonly string $name,
    ) {}
}
