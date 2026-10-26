<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Site;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Contracts\ContractConsumption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsFixtures;
use Tests\TestCase;

class DemoDataCommandTest extends TestCase
{
    use ProjectsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeBusinessTime();
    }

    #[Test]
    public function genera_la_empresa_de_demostracion_con_sus_casos_especiales(): void
    {
        $this->artisan('nova:demo')->expectsOutputToContain('Casos para la presentación')->assertSuccessful();

        $people = User::query()->where('email', 'like', '%@demo.nexus.test')->with('staffProfile.site')->get();
        $this->assertCount(47, $people);
        $this->assertSame(['Bogotá' => 10, 'Medellín' => 37], $people->countBy(fn (User $u) => $u->staffProfile->site->name)->sortKeys()->all());
        $this->assertSame(12, Client::query()->count());
        $this->assertSame(18, Contract::query()->count());
        $this->assertGreaterThanOrEqual(15, Project::query()->count());

        // Nadie puede iniciar sesión con estas cuentas.
        $this->assertTrue($people->every(fn (User $u) => $u->microsoft_oid === null));

        // Proyecto en riesgo: más del 90 % de la bolsa consumida.
        $portal = Contract::query()->where('name', 'Portal de asociados')->sole();
        $this->assertGreaterThan(0.9, app(ContractConsumption::class)->summarize($portal)['consumed_ratio']);

        // Hay horas pendientes de aprobar y algún rechazo para mostrar el flujo.
        $this->assertGreaterThan(0, TimeEntry::query()->where('status', 'submitted')->count());
        $this->assertGreaterThan(0, TimeEntry::query()->where('status', 'rejected')->count());

        // Las reglas del negocio se cumplen también en los datos de demostración.
        $this->assertSame(0, TimeEntry::query()->whereColumn('user_id', 'reviewed_by')->count(), 'Nadie aprueba sus propias horas.');
        $this->assertSame(0, DB::table('time_entries')->selectRaw('user_id, work_date')->groupBy('user_id', 'work_date')->havingRaw('SUM(minutes) > 1440')->count(), 'Ningún día pasa de 24 horas.');
        $this->assertSame(0, TimeEntry::query()->where('work_date', '>', '2026-10-21')->count(), 'No hay horas en el futuro.');
        $this->assertSame(0, TimeEntry::query()->where('status', 'approved')->whereNull('cost_rate')->count());
    }

    #[Test]
    public function no_duplica_datos_y_se_puede_regenerar_o_borrar(): void
    {
        $this->artisan('nova:demo')->assertSuccessful();
        $this->artisan('nova:demo')->expectsOutputToContain('Usa --reset')->assertFailed();

        $this->artisan('nova:demo --reset')->assertSuccessful();
        $this->assertSame(47, User::query()->where('email', 'like', '%@demo.nexus.test')->count());

        $this->artisan('nova:demo --remove')->assertSuccessful();
        $this->assertSame(0, User::query()->where('email', 'like', '%@demo.nexus.test')->count());
        $this->assertSame(0, Contract::query()->count());

        // Lo que no es de demostración (sedes, roles) se conserva.
        $this->assertSame(3, Site::query()->count());
    }

    #[Test]
    public function no_borra_a_ciegas_lo_que_se_creo_en_ensayos_sobre_la_demo(): void
    {
        $this->artisan('nova:demo')->assertSuccessful();

        // En un ensayo alguien registró un contrato real en un cliente ficticio
        // y un proyecto real sobre un contrato ficticio.
        $demoClient = Client::query()->where('code', 'like', 'DEMO-%')->firstOrFail();
        $rehearsal = $this->contract(\App\Enums\BillingModel::MonthlyRecurring, ['client_id' => $demoClient->id, 'code' => 'CT-2026-001', 'monthly_fee' => 1000000]);
        $onDemo = $this->project(Contract::query()->where('code', 'like', 'DEMO-%')->firstOrFail(), null, ['code' => 'PRY-2026-001']);
        $onRehearsal = $this->project($rehearsal, null, ['code' => 'PRY-2026-002']);

        $this->artisan('nova:demo --remove')
            ->expectsOutputToContain('CT-2026-001, PRY-2026-001, PRY-2026-002')
            ->assertFailed();
        $this->assertSame(12, Client::query()->count(), 'Sin --with-linked no se borra nada.');

        $this->artisan('nova:demo --remove --with-linked')
            ->expectsOutputToContain('También se borraron')
            ->assertSuccessful();

        $this->assertSame(0, Client::query()->count());
        $this->assertSame(0, Contract::query()->count());
        $this->assertNull(Project::query()->find($onDemo->id));
        $this->assertNull(Project::query()->find($onRehearsal->id));
    }

    #[Test]
    public function el_presentador_queda_como_lider_con_horas_por_aprobar(): void
    {
        $this->seedCatalog();
        $presenter = $this->superAdmin(['email' => 'presentador@empresa.test']);

        $this->artisan('nova:demo', ['--presenter' => 'presentador@empresa.test'])->assertSuccessful();

        $led = Project::query()->where('leader_id', $presenter->id)->pluck('id');
        $this->assertCount(2, $led);
        $this->assertGreaterThan(0, TimeEntry::query()->whereIn('project_id', $led)->where('status', 'submitted')->count());
        $this->assertNotNull($presenter->fresh()->staffProfile);

        $this->artisan('nova:demo', ['--presenter' => 'nadie@empresa.test', '--reset' => true])->assertFailed();
    }

    #[Test]
    public function nunca_se_ejecuta_en_produccion(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('nova:demo --force')->expectsOutputToContain('nunca se cargan en producción')->assertFailed();
        $this->assertSame(0, User::query()->count());
    }
}
