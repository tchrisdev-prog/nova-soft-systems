<?php

namespace App\Services\Users;

use App\Enums\ProjectStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Allocation;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\PrivilegeGuard;
use App\Support\BusinessCalendar;
use Illuminate\Support\Facades\DB;

class UserAdministrationService
{
    public function __construct(
        private readonly PrivilegeGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Autoriza una cuenta corporativa para ingresar. La identidad real se
     * confirmará en su primer inicio de sesión con Microsoft 365.
     *
     * @param  array{name: string, email: string, role_ids?: list<int>}  $data
     */
    public function authorize(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data) {
            // Si la persona estuvo antes en la empresa y su cuenta se eliminó,
            // se recupera la misma fila: conserva su historial y su vínculo
            // con Microsoft, pero vuelve sin roles (se asignan de nuevo).
            $user = User::onlyTrashed()->where('email', $data['email'])->first();

            if ($user) {
                $user->restore();
                $user->forceFill(['name' => trim($data['name']), 'is_active' => true])->save();
                $this->audit->log('user.restored', $actor, $user, ['email' => $user->email]);
            } else {
                $user = new User(['name' => trim($data['name']), 'email' => $data['email']]);
                $user->forceFill(['is_active' => true])->save();
                $this->audit->log('user.authorized', $actor, $user, ['email' => $user->email]);
            }

            if (! empty($data['role_ids'])) {
                $this->applyRoles($actor, $user, $data['role_ids']);
            }

            return $user->load('roles');
        });
    }

    public function changeStatus(User $actor, User $target, bool $active): User
    {
        $this->guard->assertCanManageUser($actor, $target);

        if ($target->is_active === $active) {
            return $target;
        }

        return DB::transaction(function () use ($actor, $target, $active) {
            if (! $active && $target->hasSuperAdminRole()) {
                $this->assertAnotherActiveSuperAdminExists($target);
            }

            $target->forceFill(['is_active' => $active])->save();

            if (! $active) {
                // Cierra de inmediato las sesiones abiertas de la cuenta desactivada.
                DB::table(config('session.table', 'sessions'))->where('user_id', $target->getKey())->delete();
            }

            $this->audit->log('user.status_changed', $actor, $target, ['is_active' => $active]);

            return $target->load('roles');
        });
    }

    /**
     * Elimina una cuenta. No se borra la fila: se marca como eliminada para
     * que la auditoría, las horas y el historial sigan mostrando quién hizo
     * cada cosa. La persona pierde el acceso de inmediato y deja de aparecer
     * en las listas; si vuelve a la empresa, basta con autorizar su correo.
     */
    public function delete(User $actor, User $target): void
    {
        $this->guard->assertCanManageUser($actor, $target);

        $ledProjects = Project::query()
            ->where('leader_id', $target->getKey())
            ->where('status', '!=', ProjectStatus::Closed->value)
            ->count();

        if ($ledProjects > 0) {
            throw new BusinessRuleException(
                $ledProjects === 1
                    ? "{$target->name} lidera un proyecto abierto. Asigna otro líder antes de eliminar la cuenta."
                    : "{$target->name} lidera {$ledProjects} proyectos abiertos. Asigna otro líder antes de eliminar la cuenta.",
                'user_leads_projects',
                409,
            );
        }

        DB::transaction(function () use ($actor, $target) {
            if ($target->hasSuperAdminRole()) {
                $this->assertAnotherActiveSuperAdminExists($target);
            }

            $roles = $target->roles()->pluck('slug')->all();
            $target->roles()->detach();

            // Sus asignaciones abiertas terminan hoy: así no aparece como
            // capacidad ocupada en proyectos a los que ya no pertenece.
            $today = BusinessCalendar::today()->toDateString();
            $closedAllocations = Allocation::query()
                ->where('user_id', $target->getKey())
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>', $today))
                ->update(['end_date' => $today, 'updated_at' => now()]);

            $target->forceFill(['is_active' => false])->save();
            DB::table(config('session.table', 'sessions'))->where('user_id', $target->getKey())->delete();
            $target->delete();

            $this->audit->log('user.deleted', $actor, $target, [
                'email' => $target->email,
                'name' => $target->name,
                'removed_roles' => $roles,
                'closed_allocations' => $closedAllocations,
            ]);
        });
    }

    /**
     * @param  list<int>  $roleIds
     */
    public function syncRoles(User $actor, User $target, array $roleIds): User
    {
        $this->guard->assertCanManageUser($actor, $target);

        return DB::transaction(function () use ($actor, $target, $roleIds) {
            $this->applyRoles($actor, $target, $roleIds);

            return $target->load('roles');
        });
    }

    /**
     * @param  list<int>  $roleIds
     */
    private function applyRoles(User $actor, User $target, array $roleIds): void
    {
        $current = $target->roles()->get()->keyBy('id');
        $requested = Role::query()->whereIn('id', $roleIds)->get()->keyBy('id');

        $added = $requested->diffKeys($current);
        $removed = $current->diffKeys($requested);

        foreach ($added as $role) {
            if (! $role->is_active) {
                throw BusinessRuleException::inactiveRole();
            }
            $this->guard->assertCanAssignRole($actor, $role);
        }

        foreach ($removed as $role) {
            $this->guard->assertCanAssignRole($actor, $role);

            if ($role->isSuperAdmin() && $target->is_active) {
                $this->assertAnotherActiveSuperAdminExists($target);
            }
        }

        if ($added->isEmpty() && $removed->isEmpty()) {
            return;
        }

        $target->roles()->detach($removed->keys()->all());
        $target->roles()->attach($added->keys()->mapWithKeys(fn ($id) => [
            $id => ['assigned_by' => $actor->getKey(), 'created_at' => now()],
        ])->all());
        $target->flushPermissionCache();

        $this->audit->log('user.roles_synced', $actor, $target, [
            'added' => $added->pluck('slug')->values()->all(),
            'removed' => $removed->pluck('slug')->values()->all(),
        ]);
    }

    /**
     * Garantiza que siempre quede al menos un Super Admin activo. Bloquea la
     * fila del rol para serializar operaciones concurrentes (PostgreSQL/MySQL).
     */
    private function assertAnotherActiveSuperAdminExists(User $except): void
    {
        $role = Role::query()
            ->where('slug', config('nova.super_admin_role'))
            ->lockForUpdate()
            ->first();

        $others = $role?->users()
            ->where('users.is_active', true)
            ->where('users.id', '!=', $except->getKey())
            ->count() ?? 0;

        if ($others === 0) {
            throw BusinessRuleException::lastSuperAdmin();
        }
    }
}
