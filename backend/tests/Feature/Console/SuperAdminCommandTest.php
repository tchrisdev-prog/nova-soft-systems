<?php

namespace Tests\Feature\Console;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function aprovisiona_un_super_admin_tras_confirmacion(): void
    {
        $this->artisan('nova:super-admin', ['email' => 'Director@Empresa.test', '--name' => 'Directora TI'])
            ->expectsConfirmation('¿Asignar el rol Super Admin a Director@Empresa.test?', 'yes')
            ->assertSuccessful();

        $user = User::query()->where('email', 'director@empresa.test')->firstOrFail();
        $this->assertTrue($user->isSuperAdmin());
        $this->assertNull($user->microsoft_oid);
        $this->assertTrue(Role::superAdmin()->is_system);
    }

    #[Test]
    public function no_hace_cambios_si_se_cancela(): void
    {
        $this->artisan('nova:super-admin', ['email' => 'director@empresa.test'])
            ->expectsConfirmation('¿Asignar el rol Super Admin a director@empresa.test?', 'no')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function valida_el_correo_y_los_dominios_permitidos(): void
    {
        $this->artisan('nova:super-admin', ['email' => 'no-es-correo', '--force' => true])->assertExitCode(2);

        config(['nova.auth.allowed_email_domains' => ['empresa.test']]);
        $this->artisan('nova:super-admin', ['email' => 'alguien@gmail.com', '--force' => true])->assertExitCode(2);

        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function es_idempotente_y_reactiva_la_cuenta(): void
    {
        $this->artisan('nova:super-admin', ['email' => 'director@empresa.test', '--force' => true])->assertSuccessful();
        User::query()->update(['is_active' => false]);

        $this->artisan('nova:super-admin', ['email' => 'director@empresa.test', '--force' => true])->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertTrue(User::first()->isSuperAdmin());
        $this->assertSame(1, Role::superAdmin()->users()->count());
    }

    #[Test]
    public function la_sincronizacion_del_catalogo_es_idempotente(): void
    {
        $this->artisan('nova:sync-permissions')->assertSuccessful();
        $this->artisan('nova:sync-permissions')->assertSuccessful();

        $this->assertDatabaseCount('modules', count(config('nova.modules')));
        $this->assertDatabaseHas('permissions', ['code' => 'access_control.manage']);
        $this->assertDatabaseCount('roles', 1);
    }
}
