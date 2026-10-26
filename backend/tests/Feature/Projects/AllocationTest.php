<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\ProjectStatus;
use App\Enums\TimeEntryStatus;
use App\Models\Allocation;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsFixtures;
use Tests\TestCase;

class AllocationTest extends TestCase
{
    use ProjectsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectsModule();
        $this->freezeBusinessTime();
    }

    #[Test]
    public function la_sobreasignacion_se_confirma_y_queda_en_la_auditoria(): void
    {
        $leader = $this->userWithRole('lider-de-proyecto');
        $consultant = $this->userWithRole('consultor');
        $current = $this->project($this->hourBagContract());
        $project = $this->project($this->hourBagContract(), $leader);
        $this->allocate($consultant, $current, 60, '2026-10-01');

        $payload = ['user_id' => $consultant->id, 'allocation_percent' => 60, 'start_date' => '2026-10-15', 'role_label' => 'Backend'];
        $this->actingAs($leader, 'sanctum');

        $this->postJson("/api/v1/projects/{$project->id}/allocations", $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'overallocation');

        $this->postJson("/api/v1/projects/{$project->id}/allocations", $payload + ['confirm_overallocation' => true])
            ->assertCreated()
            ->assertJsonPath('data.allocation_percent', 60);

        $log = AuditLog::query()->where('action', 'allocation.created')->sole();
        $this->assertSame(120, $log->metadata['peak_percent']);
        $this->assertTrue($log->metadata['overallocated']);

        // Una asignación no tiene nombre: la auditoría igual debe poder mostrarla.
        $this->seedCatalog();
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.subject.type', 'allocation')
            ->assertJsonPath('data.0.subject.label', '#'.$log->auditable_id);
    }

    #[Test]
    public function asignaciones_que_no_se_cruzan_no_cuentan_como_sobreasignacion(): void
    {
        $manager = $this->userWithRole('gerencia');
        $consultant = $this->userWithRole('consultor');
        $this->allocate($consultant, $this->project($this->hourBagContract()), 100, '2026-01-01', '2026-09-30');
        $project = $this->project($this->hourBagContract());

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/projects/{$project->id}/allocations", ['user_id' => $consultant->id, 'allocation_percent' => 100, 'start_date' => '2026-10-01'])
            ->assertCreated();
    }

    #[Test]
    public function un_lider_no_asigna_personas_en_proyectos_que_no_lidera(): void
    {
        $leader = $this->userWithRole('lider-de-proyecto');
        $project = $this->project($this->hourBagContract(), $this->userWithRole('lider-de-proyecto'));

        $this->actingAs($leader, 'sanctum')
            ->postJson("/api/v1/projects/{$project->id}/allocations", ['user_id' => $leader->id, 'allocation_percent' => 50, 'start_date' => '2026-10-01'])
            ->assertForbidden();
    }

    #[Test]
    public function subir_el_porcentaje_revisa_la_sobreasignacion_pero_bajarlo_siempre_se_permite(): void
    {
        $leader = $this->userWithRole('lider-de-proyecto');
        $consultant = $this->userWithRole('consultor');
        $other = $this->project($this->hourBagContract());
        $project = $this->project($this->hourBagContract(), $leader);
        $this->allocate($consultant, $other, 70, '2026-10-01');
        $allocation = $this->allocate($consultant, $project, 30, '2026-10-01');

        $this->actingAs($leader, 'sanctum');
        $url = "/api/v1/projects/{$project->id}/allocations/{$allocation->id}";

        $this->patchJson($url, ['allocation_percent' => 50])
            ->assertConflict()
            ->assertJsonPath('code', 'overallocation');

        $this->patchJson($url, ['allocation_percent' => 50, 'confirm_overallocation' => true])
            ->assertOk()
            ->assertJsonPath('data.allocation_percent', 50);

        // Bajar la carga de alguien sobreasignado no pide confirmación.
        $this->patchJson($url, ['allocation_percent' => 40, 'role_label' => 'QA'])
            ->assertOk()
            ->assertJsonPath('data.role_label', 'QA');

        $this->patchJson($url, ['start_date' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('start_date');

        $log = AuditLog::query()->where('action', 'allocation.updated')->latest('id')->first();
        $this->assertEquals(['old' => 50, 'new' => 40], $log->metadata['changes']['allocation_percent']);
    }

    #[Test]
    public function terminar_conserva_la_historia_y_eliminar_solo_corrige_errores(): void
    {
        $manager = $this->userWithRole('gerencia');
        $worked = $this->userWithRole('consultor');
        $mistake = $this->userWithRole('consultor');
        $project = $this->project($this->hourBagContract());
        $withTime = $this->allocate($worked, $project, 50, '2026-10-01');
        $wrong = $this->allocate($mistake, $project, 50, '2026-11-01');
        $this->entry($worked, $project, TimeEntryStatus::Approved);

        $this->actingAs($manager, 'sanctum');
        $base = "/api/v1/projects/{$project->id}/allocations";

        $this->deleteJson("{$base}/{$withTime->id}")
            ->assertConflict()
            ->assertJsonPath('code', 'allocation_has_time');

        $this->postJson("{$base}/{$withTime->id}/end")
            ->assertOk()
            ->assertJsonPath('data.end_date', '2026-10-21')
            ->assertJsonPath('data.state', 'current');

        // Una asignación que no ha empezado no se "termina": se elimina.
        $this->postJson("{$base}/{$wrong->id}/end")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'allocation_not_started');

        $this->deleteJson("{$base}/{$wrong->id}")->assertOk();
        $this->assertNull(Allocation::query()->find($wrong->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'allocation.deleted']);
    }

    #[Test]
    public function una_asignacion_solo_se_toca_desde_su_proyecto_y_si_el_proyecto_sigue_abierto(): void
    {
        $manager = $this->userWithRole('gerencia');
        $consultant = $this->userWithRole('consultor');
        $project = $this->project($this->hourBagContract());
        $foreign = $this->allocate($consultant, $this->project($this->hourBagContract()), 20);
        $closed = $this->project($this->hourBagContract(), null, ['status' => ProjectStatus::Closed]);
        $old = $this->allocate($consultant, $closed, 20, '2026-01-01', '2026-06-30');

        $this->actingAs($manager, 'sanctum');

        $this->patchJson("/api/v1/projects/{$project->id}/allocations/{$foreign->id}", ['allocation_percent' => 10])->assertNotFound();

        $this->patchJson("/api/v1/projects/{$closed->id}/allocations/{$old->id}", ['allocation_percent' => 10])
            ->assertConflict()
            ->assertJsonPath('code', 'project_closed');

        $this->postJson("/api/v1/projects/{$closed->id}/allocations", ['user_id' => $consultant->id, 'allocation_percent' => 10, 'start_date' => '2026-11-01'])
            ->assertConflict()
            ->assertJsonPath('code', 'project_closed');
    }

    #[Test]
    public function un_proyecto_cerrado_no_cuenta_para_la_sobreasignacion(): void
    {
        $manager = $this->userWithRole('gerencia');
        $consultant = $this->userWithRole('consultor');
        $closed = $this->project($this->hourBagContract(), null, ['status' => ProjectStatus::Closed]);
        $this->allocate($consultant, $closed, 100, '2026-01-01');
        $project = $this->project($this->hourBagContract());

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/projects/{$project->id}/allocations", ['user_id' => $consultant->id, 'allocation_percent' => 100, 'start_date' => '2026-11-01'])
            ->assertCreated();
    }
}
