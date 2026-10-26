<?php

namespace App\Auth\EntraId;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Throwable;
use UnexpectedValueException;

/**
 * Valida el id_token (OpenID Connect) emitido por Microsoft Entra ID:
 * firma RS256 con las claves públicas del tenant (JWKS), vigencia (exp/nbf),
 * emisor, audiencia, tenant y nonce.
 */
class IdTokenValidator
{
    public function __construct(
        private readonly EntraIdConfig $config,
        private readonly Http $http,
        private readonly Cache $cache,
    ) {}

    public function validate(string $idToken, ?string $expectedNonce): EntraIdentity
    {
        $claims = $this->decode($idToken);

        if (($claims['aud'] ?? null) !== $this->config->clientId) {
            throw new AuthenticationFailed(AuthenticationFailed::INVALID_TOKEN, 'Audiencia (aud) inesperada.');
        }

        if (strcasecmp((string) ($claims['tid'] ?? ''), (string) $this->config->tenantId) !== 0) {
            throw new AuthenticationFailed(AuthenticationFailed::TENANT_MISMATCH, 'El token pertenece a otro tenant.');
        }

        if (($claims['iss'] ?? null) !== $this->config->expectedIssuer()) {
            throw new AuthenticationFailed(AuthenticationFailed::INVALID_TOKEN, 'Emisor (iss) inesperado.');
        }

        if (! $expectedNonce || ! is_string($claims['nonce'] ?? null) || ! hash_equals($expectedNonce, $claims['nonce'])) {
            throw new AuthenticationFailed(AuthenticationFailed::INVALID_TOKEN, 'Nonce inválido.');
        }

        $oid = (string) ($claims['oid'] ?? '');
        $email = (string) ($claims['email'] ?? $claims['preferred_username'] ?? $claims['upn'] ?? '');

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $oid) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AuthenticationFailed(AuthenticationFailed::INVALID_TOKEN, 'Faltan los claims oid o email/preferred_username.');
        }

        return new EntraIdentity(
            objectId: strtolower($oid),
            tenantId: strtolower((string) $claims['tid']),
            email: mb_strtolower($email),
            name: trim((string) ($claims['name'] ?? '')) ?: $email,
        );
    }

    /** @return array<string, mixed> */
    private function decode(string $idToken): array
    {
        JWT::$leeway = $this->config->clockLeeway;

        try {
            return (array) JWT::decode($idToken, $this->keys());
        } catch (UnexpectedValueException $e) {
            // Rotación de claves: si el "kid" no está en caché, se recarga una vez.
            if (str_contains($e->getMessage(), '"kid"')) {
                try {
                    return (array) JWT::decode($idToken, $this->keys(refresh: true));
                } catch (Throwable $retry) {
                    throw new AuthenticationFailed(AuthenticationFailed::INVALID_TOKEN, $retry->getMessage());
                }
            }

            throw new AuthenticationFailed(AuthenticationFailed::INVALID_TOKEN, $e->getMessage());
        } catch (AuthenticationFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AuthenticationFailed(AuthenticationFailed::INVALID_TOKEN, $e->getMessage());
        }
    }

    /** @return array<string, \Firebase\JWT\Key> */
    private function keys(bool $refresh = false): array
    {
        $cacheKey = 'entra-id:jwks:'.$this->config->tenantId;

        if ($refresh) {
            $this->cache->forget($cacheKey);
        }

        $jwks = $this->cache->remember($cacheKey, $this->config->jwksCacheTtl, function () {
            $response = $this->http->timeout(10)->acceptJson()->get($this->config->jwksUrl());

            if (! $response->successful() || ! is_array($response->json('keys'))) {
                throw new AuthenticationFailed(AuthenticationFailed::PROVIDER_ERROR, 'No fue posible obtener las claves públicas (JWKS).');
            }

            return $response->json();
        });

        return JWK::parseKeySet($jwks, 'RS256');
    }
}
