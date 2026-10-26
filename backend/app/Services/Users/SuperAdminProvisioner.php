<?php

namespace App\Services\Users;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\PermissionCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Aprovisionamiento controlado del Super Admin. Solo se invoca desde la
 * consola del servidor (php artisan nova:super-admin), nunca desde la API.
 * No crea credenciales: el usuario debe ingresar con su cuenta de Microsoft 365.
 */
class SuperAdminProvisioner
{
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{user: User, created: bool, already_super_admin: bool}
     */
    public function provision(string $email, ?string $name = null): array
    {
        return DB::transaction(function () use ($email, $name) {
            $role = $this->catalog->ensureSuperAdminRole();
            $email = mb_strtolower(trim($email));

            // Incluye cuentas eliminadas: si existe, se recupera la misma fila.
            $user = User::withTrashed()->where('email', $email)->first();
            $created = false;

            if ($user?->trashed()) {
                $user->restore();
            }

            if (! $user) {
                $user = new User(['email' => $email, 'name' => $name ?: $email]);
                $created = true;
            } elseif ($name) {
                $user->name = $name;
            }

            $user->forceFill(['is_active' => true])->save();

            $already = $user->roles()->whereKey($role->getKey())->exists();

            if (! $already) {
                $user->roles()->attach($role->getKey(), ['assigned_by' => null, 'created_at' => now()]);
            }

            $this->audit->log('super_admin.provisioned', null, $user, [
                'email' => $email,
                'source' => 'console',
                'created_user' => $created,
            ]);

            return ['user' => $user, 'created' => $created, 'already_super_admin' => $already];
        });
    }
}
