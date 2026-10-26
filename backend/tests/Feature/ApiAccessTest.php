<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
    }

    public static function protectedEndpoints(): array
    {
        return [
            'perfil' => ['GET', '/api/v1/me'],
            'dashboard' => ['GET', '/api/v1/dashboard'],
            'roles' => ['GET', '/api/v1/roles'],
            'crear rol' => ['POST', '/api/v1/roles'],
            'usuarios' => ['GET', '/api/v1/users'],
            'auditoría' => ['GET', '/api/v1/audit-logs'],
        ];
    }

    #[Test]
    #[DataProvider('protectedEndpoints')]
    public function sin_sesion_responde_401_con_formato_uniforme(string $method, string $uri): void
    {
        $this->json($method, $uri)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Tu sesión expiró o no has iniciado sesión.', 'code' => 'unauthenticated']);
    }

    #[Test]
    public function una_cuenta_desactivada_pierde_el_acceso_aunque_tenga_sesion(): void
    {
        $user = $this->superAdmin(['is_active' => false]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_inactive');
    }

    #[Test]
    public function un_usuario_sin_roles_no_accede_a_los_modulos_administrativos(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.permissions', [])
            ->assertJsonPath('data.is_super_admin', false);

        $this->getJson('/api/v1/roles')->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->getJson('/api/v1/permissions')->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->postJson('/api/v1/roles', ['name' => 'Intruso'])->assertForbidden();
        $this->putJson('/api/v1/roles/'.Role::superAdmin()->id.'/permissions', ['permission_ids' => []])->assertForbidden();
        $this->putJson("/api/v1/users/{$user->id}/roles", ['role_ids' => [Role::superAdmin()->id]])->assertForbidden();

        $this->assertFalse($user->fresh()->isSuperAdmin());
    }

    #[Test]
    public function el_perfil_expone_permisos_y_modulos_segun_los_roles(): void
    {
        $user = $this->userWith(['access_control.view']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')->assertOk();

        $response->assertJsonPath('data.permissions', ['access_control.view']);
        $modules = collect($response->json('data.modules'))->keyBy('code');
        $this->assertTrue($modules['access_control']['can_access']);
        $this->assertSame(['view'], $modules['access_control']['actions']);
        $this->assertFalse($modules['users']['can_access']);
        // Un módulo activo sin permisos tampoco da acceso; uno planeado nunca.
        $this->assertSame('active', $modules['projects']['status']);
        $this->assertFalse($modules['projects']['can_access']);
        $this->assertSame('planned', $modules['timesheets']['status']);
        $this->assertFalse($modules['timesheets']['can_access']);
    }

    #[Test]
    public function los_permisos_de_un_rol_inactivo_no_se_aplican(): void
    {
        $user = $this->userWith(['access_control.view']);
        $user->roles()->first()->forceFill(['is_active' => false])->save();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/roles')->assertForbidden();
    }

    #[Test]
    public function el_dashboard_solo_incluye_indicadores_permitidos(): void
    {
        $limited = $this->userWith(['access_control.view']);

        $this->actingAs($limited, 'sanctum')->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => ['modules' => ['active', 'planned'], 'roles' => ['total', 'active']]])
            ->assertJsonMissingPath('data.users')
            ->assertJsonMissingPath('data.recent_activity');

        $admin = $this->superAdmin();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.users.total', 2)
            ->assertJsonPath('data.modules.active', collect(config('nova.modules'))->where('status', 'active')->count())
            ->assertJsonStructure(['data' => ['users', 'roles', 'recent_activity']]);
    }

    #[Test]
    public function devuelve_404_uniforme_para_recursos_inexistentes(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson('/api/v1/roles/99999')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }
}
