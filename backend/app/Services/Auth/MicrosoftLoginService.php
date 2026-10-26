<?php

namespace App\Services\Auth;

use App\Auth\EntraId\AuthenticationFailed;
use App\Auth\EntraId\EntraIdentity;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Traduce una identidad validada de Microsoft 365 en un usuario del ERP.
 *
 * Autenticación (quién es) la resuelve Entra ID; aquí solo se decide si esa
 * cuenta está autorizada para ingresar. Los roles y permisos se evalúan
 * después, en cada petición.
 */
class MicrosoftLoginService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function resolveUser(EntraIdentity $identity): User
    {
        try {
            return DB::transaction(fn () => $this->resolve($identity));
        } catch (AuthenticationFailed $e) {
            $this->audit->log('auth.login_rejected', null, null, [
                'email' => $identity->email,
                'reason' => $e->reason,
            ]);

            throw $e;
        }
    }

    private function resolve(EntraIdentity $identity): User
    {
        $this->assertAllowedDomain($identity->email);

        $user = User::query()->where('microsoft_oid', $identity->objectId)->first();

        if (! $user) {
            $user = User::query()->where('email', $identity->email)->first();

            if ($user && $user->microsoft_oid && $user->microsoft_oid !== $identity->objectId) {
                // El correo ya está vinculado a otra identidad de Microsoft.
                throw new AuthenticationFailed(AuthenticationFailed::ACCOUNT_MISMATCH, 'OID distinto para el mismo correo.');
            }
        }

        // Una cuenta eliminada no vuelve a entrar por su cuenta, ni siquiera
        // con el registro automático activo: un administrador debe autorizarla.
        if (! $user && User::onlyTrashed()
            ->where(fn ($q) => $q->where('microsoft_oid', $identity->objectId)->orWhere('email', $identity->email))
            ->exists()) {
            throw new AuthenticationFailed(AuthenticationFailed::NOT_AUTHORIZED, 'La cuenta fue eliminada.');
        }

        if (! $user) {
            if (! config('nova.auth.auto_provision_users')) {
                throw new AuthenticationFailed(AuthenticationFailed::NOT_AUTHORIZED, 'Cuenta no autorizada previamente.');
            }

            $user = new User(['email' => $identity->email, 'name' => $identity->name]);
            $user->forceFill(['is_active' => true]);
            $this->audit->log('user.auto_provisioned', null, null, ['email' => $identity->email]);
        }

        if ($user->exists && ! $user->is_active) {
            throw new AuthenticationFailed(AuthenticationFailed::ACCOUNT_INACTIVE, 'Cuenta desactivada.');
        }

        $emailTaken = User::query()
            ->where('email', $identity->email)
            ->when($user->exists, fn ($q) => $q->whereKeyNot($user->getKey()))
            ->exists();

        $user->forceFill([
            'name' => $identity->name,
            'email' => $emailTaken ? $user->email : $identity->email,
            'microsoft_oid' => $identity->objectId,
            'microsoft_tenant_id' => $identity->tenantId,
            'last_login_at' => now(),
        ])->save();

        $this->audit->log('auth.login', $user, $user);

        return $user;
    }

    private function assertAllowedDomain(string $email): void
    {
        $allowed = config('nova.auth.allowed_email_domains', []);
        $domain = mb_strtolower((string) substr(strrchr($email, '@') ?: '', 1));

        if ($allowed && ! in_array($domain, $allowed, true)) {
            throw new AuthenticationFailed(AuthenticationFailed::NOT_AUTHORIZED, "Dominio {$domain} no permitido.");
        }
    }
}
