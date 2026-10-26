<?php

namespace App\Services\Authorization;

use App\Exceptions\BusinessRuleException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Reglas contra la escalada de privilegios.
 *
 * Un administrador que no es Super Admin:
 *  - no puede otorgar permisos que él mismo no tiene;
 *  - no puede modificar roles que él mismo tiene asignados;
 *  - no puede asignar ni retirar roles con permisos superiores a los suyos;
 *  - nunca puede asignar, retirar ni modificar el rol Super Admin;
 *  - no puede gestionar usuarios con más privilegios que él.
 *
 * Nadie (ni siquiera un Super Admin) puede modificar su propio estado o
 * sus propios roles desde la API; así se evita el autobloqueo accidental.
 */
class PrivilegeGuard
{
    public function assertCanManageRole(User $actor, Role $role): void
    {
        if ($role->is_system) {
            throw BusinessRuleException::protectedRole();
        }

        if ($actor->isSuperAdmin()) {
            return;
        }

        if ($actor->roles()->whereKey($role->getKey())->exists()) {
            throw BusinessRuleException::privilegeEscalation('No puedes modificar un rol que tienes asignado.');
        }

        $this->assertCanGrantPermissions(
            $actor,
            $role->permissions()->pluck('code'),
            'No puedes modificar un rol que incluye permisos que no tienes.',
        );
    }

    /**
     * @param  Collection<int, string>  $permissionCodes
     */
    public function assertCanGrantPermissions(User $actor, Collection $permissionCodes, ?string $message = null): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $missing = $permissionCodes->diff($actor->permissionCodes());

        if ($missing->isNotEmpty()) {
            throw BusinessRuleException::privilegeEscalation(
                $message ?? 'No puedes otorgar permisos que no tienes: '.$missing->implode(', ').'.'
            );
        }
    }

    public function assertCanAssignRole(User $actor, Role $role): void
    {
        if ($role->isSuperAdmin()) {
            if (! $actor->isSuperAdmin()) {
                throw BusinessRuleException::privilegeEscalation('Solo un Super Admin puede asignar o retirar el rol Super Admin.');
            }

            return;
        }

        $this->assertCanGrantPermissions(
            $actor,
            $role->permissions()->pluck('code'),
            "No puedes asignar o retirar el rol \"{$role->name}\" porque incluye permisos que no tienes.",
        );
    }

    public function assertCanManageUser(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw BusinessRuleException::selfModification();
        }

        if ($actor->isSuperAdmin()) {
            return;
        }

        if ($target->hasSuperAdminRole()) {
            throw BusinessRuleException::privilegeEscalation('Solo un Super Admin puede gestionar a otro Super Admin.');
        }

        $this->assertCanGrantPermissions(
            $actor,
            $target->grantedPermissionCodes(),
            'No puedes gestionar a un usuario que tiene permisos que tú no tienes.',
        );
    }
}
