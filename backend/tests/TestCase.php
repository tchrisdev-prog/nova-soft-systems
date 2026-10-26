<?php

namespace Tests;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\PermissionCatalog;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function seedCatalog(): void
    {
        $this->app->make(PermissionCatalog::class)->sync();
    }

    protected function superAdmin(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::superAdmin()->id, ['created_at' => now()]);

        return $user->fresh();
    }

    /**
     * Crea un rol con los permisos indicados (códigos "modulo.accion").
     */
    protected function roleWith(array $permissionCodes, array $attributes = []): Role
    {
        $role = Role::factory()->create($attributes);
        $role->permissions()->sync(Permission::query()->whereIn('code', $permissionCodes)->pluck('id'));

        return $role;
    }

    protected function userWith(array $permissionCodes, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach($this->roleWith($permissionCodes)->id, ['created_at' => now()]);

        return $user->fresh();
    }

    protected function permissionIds(array $codes): array
    {
        return Permission::query()->whereIn('code', $codes)->pluck('id')->all();
    }
}
