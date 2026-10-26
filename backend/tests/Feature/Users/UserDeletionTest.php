<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Enums\ProjectStatus;
use App\Enums\TimeEntryStatus;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsFixtures;
use Tests\TestCase;

class UserDeletionTest extends TestCase
{
    use ProjectsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectsModule();
        $this->freezeBusinessTime();
    }

    #[Test]
    public function eliminar_quita_el_acceso_y_la_cuenta_de_las_listas_pero_conserva_su_historial(): void
    {
        $admin = $this->superAdmin();
        $target = $this->userWithRole('consultor');
        $project = $this->project($this->hourBagContract(), $this->userWithRole('lider-de-proyecto'));
        $allocation = $this->allocate($target, $project, 50);
        $entry = $this->entry($target, $project, TimeEntryStatus::Approved);
        DB::table('sessions')->insert(['id' => 'sesion-abierta', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('message', "Cuenta de {$target->name} eliminada. Su historial se conserva.");

        // Ya no aparece ni se puede consultar...
        $this->assertSoftDeleted($target);
        $this->getJson('/api/v1/users')->assertJsonMissing(['email' => $target->email]);
        $this->getJson("/api/v1/users/{$target->id}")->assertNotFound();

        // ...pierde el acceso de inmediato...
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertSame(0, $target->roles()->count());
        $this->assertSame('2026-10-21', $allocation->fresh()->end_date->toDateString());

        // ...y su historial sigue diciendo quién fue.
        $this->assertSame($target->name, $entry->fresh()->user->name);
        $log = AuditLog::query()->where('action', 'user.deleted')->sole();
        $this->assertSame(['consultor'], $log->metadata['removed_roles']);
        $this->getJson('/api/v1/audit-logs?action=user.deleted')
            ->assertOk()
            ->assertJsonPath('data.0.actor.id', $admin->id)
            ->assertJsonPath('data.0.subject.label', $target->name);
    }

    #[Test]
    public function exige_el_permiso_de_eliminar_usuarios(): void
    {
        $target = User::factory()->create();
        $admin = $this->userWith(['users.view', 'users.update', 'users.manage']);

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/users/{$target->id}")->assertForbidden();
        $this->assertNotSoftDeleted($target);
    }

    #[Test]
    public function nadie_se_elimina_a_si_mismo_ni_deja_el_sistema_sin_super_admin(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/users/{$admin->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'self_modification');

        // Otro administrador con permiso de eliminar intenta borrar al único Super Admin.
        $deleter = $this->userWith(['users.view', 'users.delete']);
        $this->actingAs($deleter, 'sanctum')
            ->deleteJson("/api/v1/users/{$admin->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'privilege_escalation');

        // Un Super Admin puede eliminar a otro, siempre que quede al menos uno activo.
        $second = $this->superAdmin();
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/users/{$second->id}")->assertOk();

        $inactiveOnly = $this->superAdmin(['is_active' => false]);
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/users/{$inactiveOnly->id}")->assertOk();
    }

    #[Test]
    public function un_administrador_no_elimina_cuentas_con_mas_permisos_que_los_suyos(): void
    {
        $deleter = $this->userWith(['users.view', 'users.delete']);
        $manager = $this->userWithRole('gerencia');

        $this->actingAs($deleter, 'sanctum')
            ->deleteJson("/api/v1/users/{$manager->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'privilege_escalation');
    }

    #[Test]
    public function no_se_elimina_a_quien_lidera_proyectos_abiertos(): void
    {
        $leader = $this->userWithRole('lider-de-proyecto');
        $open = $this->project($this->hourBagContract(), $leader);
        $this->project($this->hourBagContract(), $leader, ['status' => ProjectStatus::Closed]);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->deleteJson("/api/v1/users/{$leader->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'user_leads_projects');

        $open->forceFill(['status' => ProjectStatus::Closed])->save();
        $this->deleteJson("/api/v1/users/{$leader->id}")->assertOk();
    }

    #[Test]
    public function volver_a_autorizar_el_correo_recupera_la_misma_cuenta_sin_roles(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->linked()->create(['email' => 'regresa@empresa.test']);
        $target->roles()->attach(Role::query()->where('slug', 'consultor')->value('id'), ['created_at' => now()]);
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/users/{$target->id}")->assertOk();

        $this->postJson('/api/v1/users', ['name' => 'Persona que regresa', 'email' => 'regresa@empresa.test'])
            ->assertCreated()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.roles', []);

        $this->assertNotSoftDeleted($target);
        $this->assertSame($target->microsoft_oid, $target->fresh()->microsoft_oid);
        $this->assertTrue(AuditLog::query()->where('action', 'user.restored')->exists());
    }

    #[Test]
    public function el_comando_de_super_admin_tambien_recupera_una_cuenta_eliminada(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['email' => 'director@empresa.test']);
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/users/{$target->id}")->assertOk();

        $this->artisan('nova:super-admin director@empresa.test --force')->assertSuccessful();

        $this->assertNotSoftDeleted($target);
        $this->assertTrue($target->fresh()->isSuperAdmin());
    }
}
