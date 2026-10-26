<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Deja listo el módulo: sedes y plantillas de roles.
 *
 * Los roles son un punto de partida. Después el Super Admin puede ajustarlos
 * desde Roles y permisos, y volver a correr el comando no pisa esos ajustes
 * (salvo que se pida explícitamente con --reset-permissions).
 */
class ModuleSetup
{
    public const SITES = [
        ['code' => 'medellin', 'name' => 'Medellín', 'city' => 'Medellín', 'sort_order' => 10],
        ['code' => 'bogota', 'name' => 'Bogotá', 'city' => 'Bogotá', 'sort_order' => 20],
        ['code' => 'remoto', 'name' => 'Remoto', 'city' => null, 'sort_order' => 30],
    ];

    /**
     * Permisos de cada plantilla. Las policies revisan estos permisos, no los
     * nombres de los roles: si mañana el rol se llama distinto, todo sigue igual.
     */
    public const ROLES = [
        'gerencia' => [
            'name' => 'Gerencia',
            'description' => 'Acceso total al módulo de proyectos: contratos, costos, márgenes y aprobación de cualquier hora.',
            'permissions' => [
                'clients.view', 'clients.create', 'clients.update', 'clients.delete', 'clients.manage',
                'projects.view', 'projects.create', 'projects.update', 'projects.delete', 'projects.manage',
                'projects.lead', 'projects.capacity', 'projects.staff', 'projects.financials',
                'timesheets.create', 'timesheets.view', 'timesheets.approve', 'timesheets.approve_any',
            ],
        ],
        'subgerencia' => [
            'name' => 'Subgerencia',
            'description' => 'Igual que Gerencia, sin eliminar clientes, contratos ni proyectos. Aprueba horas de cualquier proyecto.',
            'permissions' => [
                'clients.view', 'clients.create', 'clients.update', 'clients.manage',
                'projects.view', 'projects.create', 'projects.update', 'projects.manage',
                'projects.lead', 'projects.capacity', 'projects.staff', 'projects.financials',
                'timesheets.create', 'timesheets.view', 'timesheets.approve', 'timesheets.approve_any',
            ],
        ],
        'comercial' => [
            'name' => 'Comercial',
            'description' => 'Clientes, contratos, recargas de bolsas y disponibilidad de consultores. Sin costos internos ni márgenes.',
            'permissions' => [
                'clients.view', 'clients.create', 'clients.update', 'clients.manage',
                'projects.view', 'projects.capacity',
            ],
        ],
        'lider-de-proyecto' => [
            'name' => 'Líder de proyecto',
            'description' => 'Gestiona los proyectos que lidera: equipo, asignaciones y aprobación de horas.',
            'permissions' => [
                'clients.view',
                'projects.lead', 'projects.capacity',
                'timesheets.create', 'timesheets.approve',
            ],
        ],
        'consultor' => [
            'name' => 'Consultor',
            'description' => 'Registra y consulta sus propias horas.',
            'permissions' => ['timesheets.create'],
        ],
    ];

    /**
     * @return array{sites: list<string>, created_roles: list<string>, reset_roles: list<string>}
     */
    public function run(bool $resetPermissions = false): array
    {
        return DB::transaction(function () use ($resetPermissions) {
            $sites = [];

            foreach (self::SITES as $data) {
                $site = Site::query()->where('code', $data['code'])->first() ?? new Site;
                $site->forceFill($data + ['is_active' => true])->save();
                $sites[] = $site->name;
            }

            $created = [];
            $reset = [];

            foreach (self::ROLES as $slug => $template) {
                $role = Role::query()->where('slug', $slug)->first();
                $isNew = $role === null;

                if ($isNew) {
                    $role = new Role(['name' => $template['name'], 'description' => $template['description']]);
                    $role->forceFill(['slug' => $slug, 'is_active' => true, 'is_system' => false])->save();
                    $created[] = $template['name'];
                }

                if ($isNew || $resetPermissions) {
                    $ids = Permission::query()->whereIn('code', $template['permissions'])->pluck('id');
                    $role->permissions()->syncWithPivotValues($ids, ['created_at' => now()]);

                    if (! $isNew) {
                        $reset[] = $role->name;
                    }
                }
            }

            return ['sites' => $sites, 'created_roles' => $created, 'reset_roles' => $reset];
        });
    }
}
