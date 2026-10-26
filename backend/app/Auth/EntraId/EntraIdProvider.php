<?php

namespace App\Auth\EntraId;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Proveedor OpenID Connect para Microsoft Entra ID (tenant único).
 *
 * - Authorization Code + PKCE con cliente confidencial (secreto en el servidor).
 * - "state" contra CSRF y "nonce" contra reutilización del id_token.
 * - La identidad se obtiene del id_token validado criptográficamente, no de
 *   datos enviados por el navegador.
 */
class EntraIdProvider extends AbstractProvider
{
    protected $scopes = ['openid', 'profile', 'email'];

    protected $scopeSeparator = ' ';

    protected $usesPKCE = true;

    private ?EntraIdConfig $entraConfig = null;

    private ?IdTokenValidator $validator = null;

    private ?EntraIdentity $identity = null;

    public function withEntraConfig(EntraIdConfig $config, IdTokenValidator $validator): static
    {
        $this->entraConfig = $config;
        $this->validator = $validator;

        return $this;
    }

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->entraConfig->authorizeUrl(), $state);
    }

    protected function getCodeFields($state = null): array
    {
        $nonce = Str::random(40);
        $this->request->session()->put('entra_nonce', $nonce);

        return array_merge(parent::getCodeFields($state), [
            'nonce' => $nonce,
            'response_mode' => 'query',
        ]);
    }

    protected function getTokenUrl(): string
    {
        return $this->entraConfig->tokenUrl();
    }

    /**
     * Intercambia el código por tokens en el backend y valida el id_token.
     */
    public function user(): SocialiteUser
    {
        if ($this->user) {
            return $this->user;
        }

        if ($this->hasInvalidState()) {
            throw new InvalidStateException;
        }

        $nonce = $this->request->session()->pull('entra_nonce');

        try {
            $response = $this->getAccessTokenResponse($this->getCode());
        } catch (GuzzleException $e) {
            throw new AuthenticationFailed(AuthenticationFailed::PROVIDER_ERROR, 'Error al canjear el código de autorización: '.$e->getMessage());
        }

        $idToken = Arr::get($response, 'id_token');

        if (! is_string($idToken) || $idToken === '') {
            throw new AuthenticationFailed(AuthenticationFailed::INVALID_TOKEN, 'La respuesta no incluye id_token.');
        }

        $identity = $this->identity = $this->validator->validate($idToken, $nonce);

        $this->user = (new SocialiteUser)
            ->setRaw(['oid' => $identity->objectId, 'tid' => $identity->tenantId])
            ->map([
                'id' => $identity->objectId,
                'name' => $identity->name,
                'email' => $identity->email,
            ]);

        // No se conservan tokens de Microsoft: solo se necesitaba confirmar la identidad.
        return $this->user;
    }

    /** Identidad validada del usuario que regresa de Microsoft. */
    public function identity(): EntraIdentity
    {
        $this->user();

        return $this->identity;
    }

    protected function getUserByToken($token): array
    {
        return [];
    }

    protected function mapUserToObject(array $user): SocialiteUser
    {
        return (new SocialiteUser)->setRaw($user);
    }
}
