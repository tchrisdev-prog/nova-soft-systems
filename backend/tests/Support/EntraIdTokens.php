<?php

namespace Tests\Support;

use Firebase\JWT\JWT;

/**
 * Genera claves RSA y id_tokens firmados localmente para probar la
 * validación sin contactar a Microsoft. Solo para pruebas.
 */
trait EntraIdTokens
{
    protected string $tenantId = '11111111-2222-3333-4444-555555555555';

    protected string $clientId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    /** @var array{private: string, jwk: array} */
    private array $signingKey;

    protected function configureEntraId(): void
    {
        config([
            'services.microsoft.tenant_id' => $this->tenantId,
            'services.microsoft.client_id' => $this->clientId,
            'services.microsoft.client_secret' => 'test-secret-not-real',
            'services.microsoft.redirect' => 'http://localhost:5173/auth/microsoft/callback',
            'services.microsoft.authority' => 'https://login.microsoftonline.com',
            'nova.frontend_url' => 'http://localhost:5173',
        ]);

        $this->signingKey = $this->makeRsaKey('test-kid-1');
    }

    protected function makeRsaKey(string $kid): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        // En Windows, PHP necesita un openssl.cnf para generar claves. Si la
        // variable OPENSSL_CONF no está definida, se usa el que trae PHP.
        $config = $this->openSslConfigPath();
        if ($config !== null) {
            $options['config'] = $config;
        }

        $resource = openssl_pkey_new($options);
        if ($resource === false) {
            $this->fail('OpenSSL no pudo generar una clave RSA de prueba. Define OPENSSL_CONF con la ruta de openssl.cnf. Detalle: '.openssl_error_string());
        }

        openssl_pkey_export($resource, $private, null, $config !== null ? ['config' => $config] : []);
        $details = openssl_pkey_get_details($resource);

        return [
            'private' => $private,
            'jwk' => [
                'kty' => 'RSA',
                'use' => 'sig',
                'kid' => $kid,
                'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
                'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
            ],
        ];
    }

    private function openSslConfigPath(): ?string
    {
        $candidates = [
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf',
        ];

        foreach ($candidates as $path) {
            if ($path && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function jwksUrl(): string
    {
        return "https://login.microsoftonline.com/{$this->tenantId}/discovery/v2.0/keys";
    }

    protected function jwks(?array $key = null): array
    {
        return ['keys' => [($key ?? $this->signingKey)['jwk']]];
    }

    protected function idToken(array $overrides = [], ?array $key = null): string
    {
        $key ??= $this->signingKey;
        $now = time();

        $claims = array_merge([
            'aud' => $this->clientId,
            'iss' => "https://login.microsoftonline.com/{$this->tenantId}/v2.0",
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
            'tid' => $this->tenantId,
            'oid' => '0f0f0f0f-1111-2222-3333-444444444444',
            'name' => 'Laura Gómez',
            'preferred_username' => 'Laura.Gomez@Empresa.test',
            'nonce' => 'expected-nonce',
        ], $overrides);

        return JWT::encode(array_filter($claims, fn ($v) => $v !== null), $key['private'], 'RS256', $key['jwk']['kid']);
    }
}
