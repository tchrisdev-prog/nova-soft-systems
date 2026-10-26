<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\ModuleStatus;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ModuleSetupTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function crea_sedes_y_plantillas_sin_pisar_los_ajustes_del_administrador(): void
    {
        $this->artisan('nova:setup-projects')->expectsOutputToContain('Roles creados: Gerencia, Subgerencia, Comercial, Líder de proyecto, Consultor.')->assertSuccessful();

        $this->assertSame(['medellin', 'bogota', 'remoto'], Site::query()->orderBy('sort_order')->pluck('code')->all());
        $consultant = Role::query()->where('slug', 'consultor')->firstOrFail();
        $this->assertSame(['timesheets.create'], $consultant->permissions()->pluck('code')->all());

        // El administrador le da un permiso extra al rol...
        $consultant->permissions()->attach(Permission::query()->where('code', 'projects.capacity')->value('id'), ['created_at' => now()]);

        // ...y volver a correr el comando no lo quita.
        $this->artisan('nova:setup-projects')->expectsOutputToContain('ya existían')->assertSuccessful();
        $this->assertCount(2, $consultant->permissions()->get());

        // Solo si se pide explícitamente.
        $this->artisan('nova:setup-projects --reset-permissions')->assertSuccessful();
        $this->assertSame(['timesheets.create'], $consultant->permissions()->pluck('code')->all());
    }

    #[Test]
    public function el_catalogo_retira_los_modulos_que_ya_no_se_desarrollaran(): void
    {
        $this->artisan('nova:sync-permissions')->assertSuccessful();

        // Un módulo que existía antes en la base de datos (de la versión anterior del catálogo).
        $old = new Module;
        $old->forceFill(['code' => 'inventory', 'name' => 'Inventario', 'status' => ModuleStatus::Planned, 'sort_order' => 1])->save();
        $permission = new Permission;
        $permission->forceFill(['module_id' => $old->id, 'action' => 'view', 'code' => 'inventory.view'])->save();

        $this->artisan('nova:sync-permissions')->expectsOutputToContain('inventory')->assertSuccessful();
        $this->assertModelExists($old);

        $this->artisan('nova:sync-permissions --prune')->assertSuccessful();
        $this->assertModelMissing($old);
        $this->assertModelMissing($permission);

        $this->assertSame(
            ['access_control', 'audit', 'clients', 'projects', 'timesheets', 'users'],
            Module::query()->orderBy('code')->pluck('code')->all(),
        );
    }
}
