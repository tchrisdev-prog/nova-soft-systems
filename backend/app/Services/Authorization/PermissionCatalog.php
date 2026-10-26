<?php

namespace App\Services\Authorization;

use App\Enums\ModuleStatus;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sincroniza el catálogo de módulos y permisos definido en config/nova.php
 * con la base de datos. Es idempotente: puede ejecutarse en cada despliegue.
 */
class PermissionCatalog
{
    /**
     * @return array{modules: int, permissions: int, removed_permissions: list<string>, removed_modules: list<string>}
     */
    public function sync(bool $prune = false): array
    {
        $modules = config('nova.modules', []);
        $actionLabels = config('nova.actions', []);

        return DB::transaction(function () use ($modules, $actionLabels, $prune) {
            $permissionCodes = [];

            foreach ($modules as $code => $definition) {
                $this->assertValidCode($code);

                $module = Module::query()->where('code', $code)->first() ?? new Module;
                $module->forceFill([
                    'code' => $code,
                    'name' => $definition['name'],
                    'description' => $definition['description'] ?? null,
                    'icon' => $definition['icon'] ?? null,
                    'status' => ModuleStatus::from($definition['status'] ?? 'planned'),
                    'sort_order' => $definition['sort_order'] ?? 0,
                ])->save();

                foreach ($this->normalizeActions($definition['actions'] ?? [], $actionLabels) as $action => $description) {
                    $this->assertValidCode($action);
                    $permissionCode = Permission::codeFor($code, $action);
                    $permissionCodes[] = $permissionCode;

                    $permission = Permission::query()->where('code', $permissionCode)->first() ?? new Permission;
                    $permission->forceFill([
                        'code' => $permissionCode,
                        'module_id' => $module->id,
                        'action' => $action,
                        'description' => $description,
                    ])->save();
                }
            }

            $removed = Permission::query()->whereNotIn('code', $permissionCodes)->pluck('code')->all();
            $removedModules = Module::query()->whereNotIn('code', array_keys($modules))->pluck('code')->all();

            if ($prune) {
                // Un módulo que salió del catálogo se lleva sus permisos y, por
                // las llaves foráneas, también las asignaciones a los roles.
                Permission::query()->whereIn('code', $removed)->delete();
                Module::query()->whereIn('code', $removedModules)->delete();
            }

            $this->ensureSuperAdminRole();

            return [
                'modules' => count($modules),
                'permissions' => count($permissionCodes),
                'removed_permissions' => $removed,
                'removed_modules' => $removedModules,
            ];
        });
    }

    /** El rol Super Admin es de sistema: siempre existe y siempre está activo. */
    public function ensureSuperAdminRole(): Role
    {
        $slug = config('nova.super_admin_role');
        $role = Role::query()->where('slug', $slug)->first() ?? new Role;

        if (! $role->exists) {
            $role->forceFill([
                'slug' => $slug,
                'name' => 'Super Admin',
                'description' => 'Rol de sistema con acceso total. Gestiona usuarios, roles y permisos.',
            ]);
        }

        $role->forceFill(['is_active' => true, 'is_system' => true])->save();

        return $role;
    }

    /**
     * Acepta tanto ['view', 'create'] como ['view' => 'Descripción'].
     *
     * @return array<string, string>
     */
    private function normalizeActions(array $actions, array $labels): array
    {
        $normalized = [];

        foreach ($actions as $key => $value) {
            if (is_int($key)) {
                $normalized[$value] = $labels[$value] ?? ucfirst($value);
            } else {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function assertValidCode(string $code): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]{1,58}$/', $code)) {
            throw new InvalidArgumentException("Código inválido en el catálogo de módulos: [{$code}].");
        }
    }
}
