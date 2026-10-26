<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Permisos base para operar sobre roles. Las reglas finas contra la
 * escalada de privilegios se aplican en App\Services\Authorization\PrivilegeGuard.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('access_control.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermission('access_control.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('access_control.create');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermission('access_control.update');
    }

    public function managePermissions(User $user, Role $role): bool
    {
        return $user->hasPermission('access_control.manage');
    }
}
