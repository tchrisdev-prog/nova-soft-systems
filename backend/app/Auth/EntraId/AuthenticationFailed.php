<?php

namespace App\Auth\EntraId;

use RuntimeException;

/**
 * Fallo en el flujo de autenticación. El "reason" es un código estable que
 * se envía al frontend (sin detalles técnicos); el mensaje queda en el log.
 */
class AuthenticationFailed extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const INVALID_STATE = 'invalid_state';
    public const INVALID_TOKEN = 'invalid_token';
    public const TENANT_MISMATCH = 'tenant_mismatch';
    public const NOT_AUTHORIZED = 'not_authorized';
    public const ACCOUNT_INACTIVE = 'account_inactive';
    public const ACCOUNT_MISMATCH = 'account_mismatch';
    public const PROVIDER_ERROR = 'provider_error';

    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct($detail ?: $reason);
    }
}
