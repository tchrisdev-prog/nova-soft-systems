<?php

namespace Tests\Feature\Users;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Users\UserAdministrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
    }

    #[Test]
    public function autoriza_una_cuenta_corporativa_con_roles_iniciales(): void
    {
        $role = Role::factory()->create();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson('/api/v1/users', ['name' => 'Carlos Ruiz', 'email' => ' Carlos.Ruiz@Empresa.test ', 'role_ids' => [$role->id]])
            ->assertCreated()
            ->assertJsonPath('data.email', 'carlos.ruiz@empresa.test')
            ->assertJsonPath('data.microsoft_linked', false)
            ->assertJsonPath('data.roles.0.id', $role->id)
            ->assertJsonMissingPath('data.microsoft_oid');

        $this->assertTrue(AuditLog::query()->where('action', 'user.authorized')->exists());
    }

    #[Test]
    public function valida_el_correo_y_los_duplicados(): void
    {
        User::factory()->create(['email' => 'existe@empresa.test']);
        $this->actingAs($this->superAdmin(), 'sanctum');

        $this->postJson('/api/v1/users', ['name' => 'Otra', 'email' => 'no-es-correo'])->assertJsonValidationErrors(['email']);
        $this->postJson('/api/v1/users', ['name' => 'Otra', 'email' => 'EXISTE@empresa.test'])
            ->assertJsonValidationErrors(['email' => 'Esta cuenta ya está registrada.']);

        config(['nova.auth.allowed_email_domains' => ['empresa.test']]);
        $this->postJson('/api/v1/users', ['name' => 'Externo', 'email' => 'alguien@gmail.com'])->assertJsonValidationErrors(['email']);
    }

    #[Test]
    public function lista_y_filtra_usuarios(): void
    {
        $role = Role::factory()->create();
        User::factory()->create(['name' => 'Ana Pérez'])->roles()->attach($role->id);
        User::factory()->inactive()->create(['name' => 'Beto Díaz']);

        $this->actingAs($this->superAdmin(['name' => 'Zoe Admin']), 'sanctum');

        $this->getJson('/api/v1/users?search=ana')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Ana Pérez');
        $this->getJson('/api/v1/users?status=inactive')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Beto Díaz');
        $this->getJson("/api/v1/users?role_id={$role->id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/users')->assertJsonCount(3, 'data');
    }

    #[Test]
    public function desactivar_un_usuario_cierra_sus_sesiones(): void
    {
        $target = User::factory()->create();
        DB::table('sessions')->insert([
            'id' => 'abc', 'user_id' => $target->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => '', 'last_activity' => time(),
        ]);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->patchJson("/api/v1/users/{$target->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
    }

    #[Test]
    public function asigna_y_retira_roles(): void
    {
        $target = User::factory()->create();
        [$a, $b] = Role::factory()->count(2)->create();
        $this->actingAs($this->superAdmin(), 'sanctum');

        $this->putJson("/api/v1/users/{$target->id}/roles", ['role_ids' => [$a->id, $b->id]])
            ->assertOk()->assertJsonCount(2, 'data.roles');
        $this->putJson("/api/v1/users/{$target->id}/roles", ['role_ids' => [$b->id]])
            ->assertOk()->assertJsonCount(1, 'data.roles');

        $this->assertSame(2, AuditLog::query()->where('action', 'user.roles_synced')->count());
    }

    #[Test]
    public function no_permite_asignar_roles_inactivos(): void
    {
        $target = User::factory()->create();
        $inactive = Role::factory()->inactive()->create();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->putJson("/api/v1/users/{$target->id}/roles", ['role_ids' => [$inactive->id]])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'inactive_role');
    }

    #[Test]
    public function solo_un_super_admin_puede_asignar_el_rol_super_admin(): void
    {
        $manager = $this->userWith(['users.view', 'users.manage']);
        $target = User::factory()->create();

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/v1/users/{$target->id}/roles", ['role_ids' => [Role::superAdmin()->id]])
            ->assertForbidden()
            ->assertJsonPath('code', 'privilege_escalation');

        $this->assertFalse($target->fresh()->hasSuperAdminRole());

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->putJson("/api/v1/users/{$target->id}/roles", ['role_ids' => [Role::superAdmin()->id]])
            ->assertOk()
            ->assertJsonPath('data.is_super_admin', true);
    }

    #[Test]
    public function un_administrador_no_puede_asignarse_roles_a_si_mismo(): void
    {
        $manager = $this->userWith(['users.view', 'users.manage', 'access_control.view']);
        $role = $this->roleWith(['access_control.view']);

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/v1/users/{$manager->id}/roles", ['role_ids' => [$role->id]])
            ->assertForbidden()
            ->assertJsonPath('code', 'self_modification');
    }

    #[Test]
    public function un_administrador_no_puede_asignar_roles_con_mas_permisos_que_los_suyos(): void
    {
        $manager = $this->userWith(['users.view', 'users.manage']);
        $target = User::factory()->create();
        $powerful = $this->roleWith(['access_control.manage']);
        $equal = $this->roleWith(['users.view']);

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/v1/users/{$target->id}/roles", ['role_ids' => [$powerful->id]])
            ->assertForbidden()
            ->assertJsonPath('code', 'privilege_escalation');

        $this->putJson("/api/v1/users/{$target->id}/roles", ['role_ids' => [$equal->id]])->assertOk();
    }

    #[Test]
    public function un_administrador_no_puede_gestionar_a_un_super_admin_ni_a_usuarios_con_mas_privilegios(): void
    {
        $manager = $this->userWith(['users.view', 'users.update', 'users.manage']);
        $super = $this->superAdmin();
        $powerfulUser = $this->userWith(['access_control.manage']);

        $this->actingAs($manager, 'sanctum');

        $this->patchJson("/api/v1/users/{$super->id}/status", ['is_active' => false])
            ->assertForbidden()->assertJsonPath('code', 'privilege_escalation');
        $this->putJson("/api/v1/users/{$super->id}/roles", ['role_ids' => []])
            ->assertForbidden()->assertJsonPath('code', 'privilege_escalation');
        $this->patchJson("/api/v1/users/{$powerfulUser->id}/status", ['is_active' => false])
            ->assertForbidden()->assertJsonPath('code', 'privilege_escalation');

        $this->assertTrue($super->fresh()->isSuperAdmin());
    }

    #[Test]
    public function un_administrador_no_puede_reactivar_a_un_super_admin_desactivado(): void
    {
        $this->superAdmin(); // otro Super Admin activo
        $dormant = $this->superAdmin(['is_active' => false]);
        $manager = $this->userWith(['users.view', 'users.update']);

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/users/{$dormant->id}/status", ['is_active' => true])
            ->assertForbidden();

        $this->assertFalse($dormant->fresh()->is_active);
    }

    #[Test]
    public function nadie_puede_desactivarse_a_si_mismo(): void
    {
        $super = $this->superAdmin();

        $this->actingAs($super, 'sanctum')
            ->patchJson("/api/v1/users/{$super->id}/status", ['is_active' => false])
            ->assertForbidden()
            ->assertJsonPath('code', 'self_modification');
    }

    #[Test]
    public function un_super_admin_puede_retirar_el_rol_a_otro_si_quedan_super_admins_activos(): void
    {
        $first = $this->superAdmin();
        $second = $this->superAdmin();

        $this->actingAs($first, 'sanctum')
            ->putJson("/api/v1/users/{$second->id}/roles", ['role_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.is_super_admin', false);
    }

    #[Test]
    public function protege_al_ultimo_super_admin_activo_incluso_ante_cambios_concurrentes(): void
    {
        // El actor fue desactivado en la base de datos mientras su petición
        // estaba en curso: el único Super Admin activo real es $last.
        $actor = $this->superAdmin(['is_active' => false]);
        $actor->is_active = true; // estado (desactualizado) en memoria
        $last = $this->superAdmin();
        $service = $this->app->make(UserAdministrationService::class);

        foreach ([
            fn () => $service->syncRoles($actor, $last, []),
            fn () => $service->changeStatus($actor, $last, false),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Debió impedirse dejar el sistema sin Super Admin activo.');
            } catch (BusinessRuleException $e) {
                $this->assertSame('last_super_admin', $e->errorCode);
            }
        }

        $this->assertTrue($last->fresh()->isSuperAdmin());
    }

    #[Test]
    public function cada_operacion_exige_su_permiso_especifico(): void
    {
        $viewer = $this->userWith(['users.view']);
        $target = User::factory()->create();

        $this->actingAs($viewer, 'sanctum');
        $this->getJson('/api/v1/users')->assertOk();
        $this->getJson("/api/v1/users/{$target->id}")->assertOk();
        $this->postJson('/api/v1/users', ['name' => 'Nuevo', 'email' => 'nuevo@empresa.test'])->assertForbidden();
        $this->patchJson("/api/v1/users/{$target->id}/status", ['is_active' => false])->assertForbidden();
        $this->putJson("/api/v1/users/{$target->id}/roles", ['role_ids' => []])->assertForbidden();
    }

    #[Test]
    public function la_auditoria_requiere_permiso_y_registra_al_actor(): void
    {
        $super = $this->superAdmin();
        $this->actingAs($super, 'sanctum')->postJson('/api/v1/users', ['name' => 'Nueva Persona', 'email' => 'nueva@empresa.test']);

        $this->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'user.authorized')
            ->assertJsonPath('data.0.actor.id', $super->id)
            ->assertJsonPath('data.0.subject.type', 'user');

        $this->actingAs($this->userWith(['users.view']), 'sanctum')->getJson('/api/v1/audit-logs')->assertForbidden();
    }
}
