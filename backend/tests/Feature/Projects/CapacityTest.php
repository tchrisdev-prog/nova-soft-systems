<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\ProjectStatus;
use App\Enums\TimeEntryStatus;
use App\Models\AuditLog;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\ColombianHolidays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsFixtures;
use Tests\TestCase;

class CapacityTest extends TestCase
{
    use ProjectsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectsModule();
        $this->freezeBusinessTime(); // miércoles 21 de octubre de 2026
    }

    #[Test]
    public function los_festivos_de_2026_coinciden_con_el_calendario_oficial(): void
    {
        $this->assertSame([
            '2026-01-01', '2026-01-12', '2026-03-23', '2026-04-02', '2026-04-03', '2026-05-01',
            '2026-05-18', '2026-06-08', '2026-06-15', '2026-06-29', '2026-07-20', '2026-08-07',
            '2026-08-17', '2026-10-12', '2026-11-02', '2026-11-16', '2026-12-08', '2026-12-25',
        ], array_keys(ColombianHolidays::forYear(2026)));

        // 2027: Semana Santa cae en marzo y San José ya es lunes 22.
        $this->assertTrue(ColombianHolidays::isHoliday(CarbonImmutable::parse('2027-03-25')));
        $this->assertTrue(ColombianHolidays::isHoliday(CarbonImmutable::parse('2027-03-22')));
        $this->assertFalse(ColombianHolidays::isHoliday(CarbonImmutable::parse('2027-03-19')));
    }

    #[Test]
    public function una_semana_con_festivo_tiene_cuatro_quintos_de_la_capacidad(): void
    {
        $manager = $this->userWithRole('gerencia');

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/capacity?weeks=4')
            ->assertOk()
            ->assertJsonCount(4, 'data.weeks')
            ->assertJsonPath('data.weeks.0.start', '2026-10-19')
            // El lunes 2 de noviembre es Todos los Santos.
            ->assertJsonPath('data.weeks.2.start', '2026-11-02')
            ->assertJsonPath('data.weeks.2.working_days', 4)
            ->assertJsonPath('data.weeks.2.holidays.0.name', 'Día de Todos los Santos');

        $me = collect($response->json('data.people'))->firstWhere('user.id', $manager->id);
        $this->assertSame(42 * 60, $me['weeks'][0]['capacity_minutes']);
        $this->assertSame(42 * 60 * 4 / 5, $me['weeks'][2]['capacity_minutes']);
    }

    #[Test]
    public function una_asignacion_que_empieza_a_mitad_de_semana_solo_cuenta_desde_ese_dia(): void
    {
        $manager = $this->userWithRole('gerencia');
        $consultant = $this->userWithRole('consultor');
        $project = $this->project($this->hourBagContract());
        $closed = $this->project($this->hourBagContract(), null, ['status' => ProjectStatus::Closed]);

        $this->allocate($consultant, $project, 50, '2026-10-21'); // miércoles
        $this->allocate($consultant, $closed, 100, '2026-01-01'); // cerrado: ya no ocupa

        $this->entry($consultant, $project, TimeEntryStatus::Approved, ['work_date' => '2026-10-21', 'minutes' => 240]);
        $this->entry($consultant, $project, TimeEntryStatus::Rejected, ['work_date' => '2026-10-21', 'minutes' => 60]);

        $response = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/capacity?weeks=4')->assertOk();
        $row = collect($response->json('data.people'))->firstWhere('user.id', $consultant->id);

        $daily = 42 * 60 / 5;
        $this->assertSame((int) round($daily * 3 * 0.5), $row['weeks'][0]['allocated_minutes']); // mié, jue, vie
        $this->assertEqualsWithDelta(0.3, $row['weeks'][0]['percent'], 0.0001);
        $this->assertSame(240, $row['weeks'][0]['actual_minutes']);
        $this->assertSame((int) round($daily * 5 * 0.5), $row['weeks'][1]['allocated_minutes']);
        $this->assertSame([$project->code], array_column($row['weeks'][0]['projects'], 'code'));
    }

    #[Test]
    public function la_busqueda_de_disponibilidad_usa_el_dia_mas_cargado_del_rango(): void
    {
        $commercial = $this->userWithRole('comercial');
        $busy = $this->userWithRole('consultor', ['name' => 'Ana Ocupada']);
        $later = $this->userWithRole('consultor', ['name' => 'Bruno Después']);
        $free = $this->userWithRole('consultor', ['name' => 'Carla Libre']);
        $some = $this->userWithRole('consultor', ['name' => 'Diego Parcial']);
        $project = $this->project($this->hourBagContract());

        $this->allocate($busy, $project, 80, '2026-10-01');
        // Libre al principio, pero a mitad del rango entra a otro proyecto.
        $this->allocate($later, $project, 70, '2026-11-15');
        $this->allocate($some, $project, 20, '2026-10-01', '2026-12-31');

        $response = $this->actingAs($commercial, 'sanctum')
            ->getJson('/api/v1/capacity/available?percent=50&from=2026-11-01&to=2026-11-30')
            ->assertOk();

        $names = array_column(array_column($response->json('data'), 'user'), 'name');
        $this->assertContains('Carla Libre', $names);
        $this->assertContains('Diego Parcial', $names);
        $this->assertNotContains('Ana Ocupada', $names);
        $this->assertNotContains('Bruno Después', $names);

        $diego = collect($response->json('data'))->firstWhere('user.name', 'Diego Parcial');
        $this->assertSame(80, $diego['free_percent']);

        // Con 0 % aparecen todos, incluso quien está lleno.
        $all = $this->getJson('/api/v1/capacity/available?percent=0&from=2026-11-01&to=2026-11-30')->assertOk();
        $this->assertContains('Ana Ocupada', array_column(array_column($all->json('data'), 'user'), 'name'));

        $this->getJson('/api/v1/capacity/available?percent=50&from=2026-11-01&to=2028-01-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    #[Test]
    public function la_capacidad_exige_permiso_y_nunca_muestra_costos(): void
    {
        $this->actingAs($this->userWithRole('consultor'), 'sanctum')
            ->getJson('/api/v1/capacity')
            ->assertForbidden();

        $response = $this->actingAs($this->userWithRole('comercial'), 'sanctum')
            ->getJson('/api/v1/capacity')
            ->assertOk()
            ->assertJsonCount(8, 'data.weeks');

        $this->assertStringNotContainsString('hourly_cost', $response->getContent());

        $this->getJson('/api/v1/capacity?weeks=6')->assertUnprocessable();
    }

    #[Test]
    public function las_cuentas_desactivadas_o_eliminadas_no_aparecen_en_la_capacidad(): void
    {
        $manager = $this->userWithRole('gerencia');
        $inactive = $this->userWithRole('consultor', ['is_active' => false]);
        $deleted = $this->userWithRole('consultor');
        $deleted->delete();

        $response = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/capacity')->assertOk();
        $ids = array_column(array_column($response->json('data.people'), 'user'), 'id');

        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($deleted->id, $ids);
    }

    #[Test]
    public function el_costo_por_hora_solo_lo_ve_y_lo_cambia_quien_tiene_permiso_de_costos(): void
    {
        $hr = $this->userWith(['projects.staff']);
        $manager = $this->userWithRole('gerencia');
        $consultant = $this->userWithRole('consultor', [], 95000);

        $listing = $this->actingAs($hr, 'sanctum')->getJson('/api/v1/staff')->assertOk();
        $this->assertStringNotContainsString('hourly_cost', $listing->getContent());

        $payload = ['site_id' => $this->site('bogota')->id, 'position' => 'Arquitecta cloud', 'specialty' => 'cloud', 'weekly_hours' => 36];

        $this->putJson("/api/v1/staff/{$consultant->id}", $payload + ['hourly_cost' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hourly_cost');

        $this->putJson("/api/v1/staff/{$consultant->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.profile.weekly_hours', 36)
            ->assertJsonPath('data.profile.specialty.value', 'cloud')
            ->assertJsonMissingPath('data.profile.hourly_cost');

        $profile = StaffProfile::query()->where('user_id', $consultant->id)->sole();
        $this->assertSame(36 * 60, $profile->weekly_capacity_minutes);
        $this->assertSame(95000, $profile->hourly_cost, 'Sin el permiso, el costo no se toca.');

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/v1/staff/{$consultant->id}", $payload + ['hourly_cost' => 120000])
            ->assertOk()
            ->assertJsonPath('data.profile.hourly_cost', 120000);

        $log = AuditLog::query()->where('action', 'staff_profile.updated')->latest('id')->first();
        $this->assertSame(['changed' => true], $log->metadata['changes']['hourly_cost']);
        $this->assertStringNotContainsString('120000', json_encode($log->metadata));

        $this->actingAs($this->userWithRole('comercial'), 'sanctum')
            ->getJson('/api/v1/staff')
            ->assertForbidden();
    }

    #[Test]
    public function se_puede_crear_el_perfil_de_quien_entro_por_primera_vez(): void
    {
        $manager = $this->userWithRole('gerencia');
        $newcomer = User::factory()->create(['name' => 'Persona Nueva']);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/staff?missing=1')
            ->assertOk()
            ->assertJsonPath('meta.missing_profiles', 1)
            ->assertJsonPath('data.0.user.name', 'Persona Nueva')
            ->assertJsonPath('data.0.profile', null);

        $this->putJson("/api/v1/staff/{$newcomer->id}", [
            'site_id' => $this->site()->id,
            'position' => 'Desarrolladora',
            'specialty' => 'development',
            'weekly_hours' => 42,
            'hourly_cost' => 70000,
        ])->assertOk();

        $this->assertDatabaseHas('staff_profiles', ['user_id' => $newcomer->id, 'weekly_capacity_minutes' => 2520, 'hourly_cost' => 70000]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff_profile.created']);
    }
}
