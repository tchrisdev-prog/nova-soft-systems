<?php

namespace Tests\Feature\Roles;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
    }

    #[Test]
    public function el_super_admin_crea_un_rol_con_slug_estable(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson('/api/v1/roles', ['name' => '  Jefe   de Compras ', 'description' => 'Aprueba órdenes'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Jefe de Compras')
            ->assertJsonPath('data.slug', 'jefe-de-compras')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('message', 'Rol creado correctamente.');

        $this->assertTrue(AuditLog::query()->where('action', 'role.created')->exists());
    }

    #[Test]
    public function valida_los_datos_del_rol(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum');

        $this->postJson('/api/v1/roles', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/v1/roles', ['name' => 'ab'])->assertJsonValidationErrors(['name']);
        $this->postJson('/api/v1/roles', ['name' => '<script>'])->assertJsonValidationErrors(['name']);
        $this->postJson('/api/v1/roles', ['name' => 'Válido', 'description' => str_repeat('x', 501)])
            ->assertJsonValidationErrors(['description']);
    }

    #[Test]
    public function evita_roles_duplicados_y_equivalentes(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum');
        $this->postJson('/api/v1/roles', ['name' => 'Contador'])->assertCreated();

        $this->postJson('/api/v1/roles', ['name' => 'CONTADOR'])
            ->assertJsonValidationErrors(['name' => 'Ya existe un rol con ese nombre.']);
        $this->postJson('/api/v1/roles', ['name' => 'Contador.'])
            ->assertJsonValidationErrors(['name' => 'Ya existe un rol con un nombre equivalente.']);
        $this->postJson('/api/v1/roles', ['name' => 'Super Admin'])->assertJsonValidationErrors(['name']);
        $this->postJson('/api/v1/roles', ['name' => 'Super-Admin'])->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function lista_busca_y_filtra_roles(): void
    {
        Role::factory()->create(['name' => 'Analista de inventario', 'slug' => 'analista-de-inventario']);
        Role::factory()->inactive()->create(['name' => 'Auxiliar contable', 'slug' => 'auxiliar-contable']);

        $this->actingAs($this->superAdmin(), 'sanctum');

        $this->getJson('/api/v1/roles?search=inventario')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Analista de inventario');

        $this->getJson('/api/v1/roles?status=inactive')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'auxiliar-contable');

        $this->getJson('/api/v1/roles?status=otro')->assertJsonValidationErrors(['status']);
        $this->getJson('/api/v1/roles')->assertJsonStructure(['data', 'links', 'meta' => ['total', 'current_page']]);
    }

    #[Test]
    public function edita_y_cambia_el_estado_de_un_rol(): void
    {
        $role = Role::factory()->create(['name' => 'Ventas', 'slug' => 'ventas']);
        $this->actingAs($this->superAdmin(), 'sanctum');

        $this->putJson("/api/v1/roles/{$role->id}", ['name' => 'Ventas nacionales', 'description' => 'Equipo comercial'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ventas nacionales')
            ->assertJsonPath('data.slug', 'ventas');

        $this->patchJson("/api/v1/roles/{$role->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(
            ['role.updated', 'role.status_changed'],
            AuditLog::query()->orderBy('id')->pluck('action')->all(),
        );
    }

    #[Test]
    public function asigna_y_retira_permisos(): void
    {
        $role = Role::factory()->create();
        $this->actingAs($this->superAdmin(), 'sanctum');

        $ids = $this->permissionIds(['users.view', 'users.update', 'projects.view']);

        $this->putJson("/api/v1/roles/{$role->id}/permissions", ['permission_ids' => $ids])
            ->assertOk()
            ->assertJsonPath('data.permissions_count', 3)
            ->assertJsonPath('data.module_codes', ['projects', 'users']);

        $this->putJson("/api/v1/roles/{$role->id}/permissions", ['permission_ids' => $this->permissionIds(['users.view'])])
            ->assertOk()
            ->assertJsonPath('data.permissions_count', 1);

        $log = AuditLog::query()->where('action', 'role.permissions_synced')->latest('id')->first();
        $this->assertEqualsCanonicalizing(['projects.view', 'users.update'], $log->metadata['removed']);

        $this->putJson("/api/v1/roles/{$role->id}/permissions", ['permission_ids' => [999999]])
            ->assertJsonValidationErrors(['permission_ids.0']);
    }

    #[Test]
    public function el_rol_super_admin_esta_protegido(): void
    {
        $superRole = Role::superAdmin();
        $this->actingAs($this->superAdmin(), 'sanctum');

        $this->putJson("/api/v1/roles/{$superRole->id}", ['name' => 'Otro nombre'])
            ->assertForbidden()->assertJsonPath('code', 'protected_role');
        $this->patchJson("/api/v1/roles/{$superRole->id}/status", ['is_active' => false])
            ->assertForbidden()->assertJsonPath('code', 'protected_role');
        $this->putJson("/api/v1/roles/{$superRole->id}/permissions", ['permission_ids' => []])
            ->assertForbidden()->assertJsonPath('code', 'protected_role');

        $this->assertTrue($superRole->fresh()->is_active);
    }

    #[Test]
    public function un_administrador_no_puede_otorgar_permisos_que_no_tiene(): void
    {
        $admin = $this->userWith(['access_control.view', 'access_control.manage', 'projects.view']);
        $role = Role::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/roles/{$role->id}/permissions", ['permission_ids' => $this->permissionIds(['projects.view', 'users.manage'])])
            ->assertForbidden()
            ->assertJsonPath('code', 'privilege_escalation');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/roles/{$role->id}/permissions", ['permission_ids' => $this->permissionIds(['projects.view'])])
            ->assertOk();
    }

    #[Test]
    public function un_administrador_no_puede_ampliar_su_propio_rol(): void
    {
        $admin = $this->userWith(['access_control.view', 'access_control.update', 'access_control.manage']);
        $ownRole = $admin->roles()->first();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/roles/{$ownRole->id}/permissions", ['permission_ids' => $this->permissionIds(['access_control.view'])])
            ->assertForbidden()
            ->assertJsonPath('code', 'privilege_escalation');

        $this->patchJson("/api/v1/roles/{$ownRole->id}/status", ['is_active' => false])->assertForbidden();
    }

    #[Test]
    public function un_administrador_no_puede_modificar_roles_con_mas_privilegios(): void
    {
        $admin = $this->userWith(['access_control.view', 'access_control.update']);
        $powerful = $this->roleWith(['users.manage']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/roles/{$powerful->id}/status", ['is_active' => false])
            ->assertForbidden()
            ->assertJsonPath('code', 'privilege_escalation');
    }

    #[Test]
    public function cada_operacion_exige_su_permiso_especifico(): void
    {
        $viewer = $this->userWith(['access_control.view']);
        $role = Role::factory()->create();

        $this->actingAs($viewer, 'sanctum');
        $this->getJson('/api/v1/roles')->assertOk();
        $this->getJson("/api/v1/roles/{$role->id}")->assertOk();
        $this->getJson('/api/v1/permissions')->assertOk();
        $this->postJson('/api/v1/roles', ['name' => 'Nuevo'])->assertForbidden();
        $this->putJson("/api/v1/roles/{$role->id}", ['name' => 'Cambio'])->assertForbidden();
        $this->patchJson("/api/v1/roles/{$role->id}/status", ['is_active' => false])->assertForbidden();
        $this->putJson("/api/v1/roles/{$role->id}/permissions", ['permission_ids' => []])->assertForbidden();
    }

    #[Test]
    public function la_matriz_de_permisos_se_agrupa_por_modulo(): void
    {
        $response = $this->actingAs($this->superAdmin(), 'sanctum')->getJson('/api/v1/permissions')->assertOk();

        $modules = collect($response->json('data'))->keyBy('code');
        $this->assertSame(['view', 'create', 'update', 'manage'], collect($modules['access_control']['permissions'])->pluck('action')->all());
        $this->assertSame(Permission::count(), $modules->sum(fn ($m) => count($m['permissions'])));
    }
}
