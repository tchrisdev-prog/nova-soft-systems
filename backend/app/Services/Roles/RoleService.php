<?php

namespace App\Services\Roles;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\PrivilegeGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoleService
{
    public function __construct(
        private readonly PrivilegeGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public static function slugFor(string $name): string
    {
        return Str::slug($name) ?: 'rol';
    }

    /**
     * @param  array{name: string, description?: string|null}  $data
     */
    public function create(User $actor, array $data): Role
    {
        return DB::transaction(function () use ($actor, $data) {
            $role = new Role(['name' => trim($data['name']), 'description' => $data['description'] ?? null]);
            $role->forceFill([
                'slug' => self::slugFor($data['name']),
                'is_active' => true,
                'is_system' => false,
            ])->save();

            $this->audit->log('role.created', $actor, $role, ['name' => $role->name]);

            return $role;
        });
    }

    /**
     * Renombrar no cambia el slug: es el identificador estable del rol.
     *
     * @param  array{name: string, description?: string|null}  $data
     */
    public function update(User $actor, Role $role, array $data): Role
    {
        $this->guard->assertCanManageRole($actor, $role);

        return DB::transaction(function () use ($actor, $role, $data) {
            $role->fill(['name' => trim($data['name']), 'description' => $data['description'] ?? null]);
            $changes = $role->getDirty();

            if ($changes) {
                $original = array_intersect_key($role->getOriginal(), $changes);
                $role->save();
                $this->audit->log('role.updated', $actor, $role, ['before' => $original, 'after' => $changes]);
            }

            return $role;
        });
    }

    public function changeStatus(User $actor, Role $role, bool $active): Role
    {
        $this->guard->assertCanManageRole($actor, $role);

        if ($role->is_active === $active) {
            return $role;
        }

        return DB::transaction(function () use ($actor, $role, $active) {
            $role->forceFill(['is_active' => $active])->save();
            $this->audit->log('role.status_changed', $actor, $role, [
                'is_active' => $active,
                'affected_users' => $role->users()->count(),
            ]);

            return $role;
        });
    }

    /**
     * Reemplaza el conjunto de permisos del rol.
     *
     * @param  list<int>  $permissionIds
     */
    public function syncPermissions(User $actor, Role $role, array $permissionIds): Role
    {
        $this->guard->assertCanManageRole($actor, $role);

        $permissions = Permission::query()->whereIn('id', $permissionIds)->get();
        $this->guard->assertCanGrantPermissions($actor, $permissions->pluck('code'));

        return DB::transaction(function () use ($actor, $role, $permissions) {
            $before = $role->permissions()->pluck('code');

            $role->permissions()->sync(
                $permissions->mapWithKeys(fn (Permission $p) => [$p->id => ['created_at' => now()]])->all()
            );

            $after = $permissions->pluck('code');
            $added = $after->diff($before)->values();
            $removed = $before->diff($after)->values();

            if ($added->isNotEmpty() || $removed->isNotEmpty()) {
                $this->audit->log('role.permissions_synced', $actor, $role, [
                    'added' => $added->all(),
                    'removed' => $removed->all(),
                ]);
            }

            return $role->load('permissions.module');
        });
    }
}
