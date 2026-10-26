<?php

namespace App\Auth\EntraId;

/**
 * Lectura y validación de la configuración de Microsoft Entra ID.
 * Solo se admite un tenant concreto (GUID): "common" u "organizations"
 * permitirían el ingreso de cuentas de cualquier organización.
 */
final class EntraIdConfig
{
    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        public readonly ?string $tenantId,
        public readonly ?string $clientId,
        public readonly ?string $clientSecret,
        public readonly ?string $redirectUri,
        public readonly string $authority,
        public readonly int $jwksCacheTtl,
        public readonly int $clockLeeway,
    ) {}

    public static function fromConfig(): self
    {
        $c = config('services.microsoft', []);

        return new self(
            tenantId: self::clean($c['tenant_id'] ?? null),
            clientId: self::clean($c['client_id'] ?? null),
            clientSecret: self::clean($c['client_secret'] ?? null),
            redirectUri: self::clean($c['redirect'] ?? null),
            authority: rtrim((string) ($c['authority'] ?? 'https://login.microsoftonline.com'), '/'),
            jwksCacheTtl: (int) ($c['jwks_cache_ttl'] ?? 21600),
            clockLeeway: (int) ($c['clock_leeway'] ?? 120),
        );
    }

    /** Lista de problemas de configuración; vacía si todo está completo. */
    public function problems(): array
    {
        $problems = [];

        if (! $this->tenantId || ! preg_match(self::GUID, $this->tenantId)) {
            $problems[] = 'MICROSOFT_TENANT_ID debe ser el identificador (GUID) del tenant corporativo.';
        }
        if (! $this->clientId || ! preg_match(self::GUID, $this->clientId)) {
            $problems[] = 'MICROSOFT_CLIENT_ID debe ser el Application (client) ID (GUID) del registro de aplicación.';
        }
        if (! $this->clientSecret) {
            $problems[] = 'MICROSOFT_CLIENT_SECRET no está definido.';
        }
        if (! $this->redirectUri || ! filter_var($this->redirectUri, FILTER_VALIDATE_URL)) {
            $problems[] = 'MICROSOFT_REDIRECT_URI debe ser una URL absoluta registrada en Entra ID.';
        }

        return $problems;
    }

    public function isConfigured(): bool
    {
        return $this->problems() === [];
    }

    public function authorizeUrl(): string
    {
        return "{$this->authority}/{$this->tenantId}/oauth2/v2.0/authorize";
    }

    public function tokenUrl(): string
    {
        return "{$this->authority}/{$this->tenantId}/oauth2/v2.0/token";
    }

    public function jwksUrl(): string
    {
        return "{$this->authority}/{$this->tenantId}/discovery/v2.0/keys";
    }

    public function expectedIssuer(): string
    {
        return "{$this->authority}/{$this->tenantId}/v2.0";
    }

    private static function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
