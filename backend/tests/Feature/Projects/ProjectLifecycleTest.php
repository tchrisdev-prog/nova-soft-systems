<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\ContractStatus;
use App\Enums\ProjectStatus;
use App\Enums\TimeEntryStatus;
use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsFixtures;
use Tests\TestCase;

class ProjectLifecycleTest extends TestCase
{
    use ProjectsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectsModule();
        $this->freezeBusinessTime(); // miércoles 21 de octubre de 2026
    }

    /** @return array<string, mixed> */
    private function payload(int $contractId, ?int $leaderId, array $overrides = []): array
    {
        return array_merge([
            'contract_id' => $contractId,
            'leader_id' => $leaderId,
            'name' => 'Migración a la nube',
            'kind' => 'project',
            'site_id' => $this->site()->id,
            'start_date' => '2026-11-03',
            'end_date' => '2026-12-18',
            'budget_hours' => 120.5,
        ], $overrides);
    }

    #[Test]
    public function gerencia_crea_proyectos_con_codigo_automatico_y_queda_planeado(): void
    {
        $manager = $this->userWithRole('gerencia');
        $leader = $this->userWithRole('lider-de-proyecto');
        $contract = $this->hourBagContract(attributes: ['status' => ContractStatus::Draft]);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/projects', $this->payload($contract->id, $leader->id))
            ->assertCreated()
            ->assertJsonPath('data.code', 'PRY-2026-001')
            ->assertJsonPath('data.status.value', 'planned')
            ->assertJsonPath('data.budget_minutes', 7230)
            ->assertJsonPath('data.leader.id', $leader->id)
            ->assertJsonPath('data.health.flags.0.code', 'no_team')
            ->assertJsonPath('data.can.delete', true);

        $this->postJson('/api/v1/projects', $this->payload($contract->id, $leader->id, ['start_date' => '2027-01-04', 'end_date' => null]))
            ->assertCreated()
            ->assertJsonPath('data.code', 'PRY-2027-001');

        $this->assertDatabaseHas('audit_logs', ['action' => 'project.created']);
    }

    #[Test]
    public function el_lider_debe_tener_permiso_para_liderar_y_el_contrato_debe_estar_abierto(): void
    {
        $manager = $this->userWithRole('gerencia');
        $consultant = $this->userWithRole('consultor');
        $contract = $this->hourBagContract();
        $finished = $this->hourBagContract(attributes: ['status' => ContractStatus::Finished]);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/projects', $this->payload($contract->id, $consultant->id))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_leader');

        $this->postJson('/api/v1/projects', $this->payload($finished->id, null))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'contract_not_open');

        $this->postJson('/api/v1/projects', $this->payload($contract->id, null, ['start_date' => '2025-12-01']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'outside_contract');

        $this->actingAs($this->userWithRole('comercial'), 'sanctum')
            ->postJson('/api/v1/projects', $this->payload($contract->id, null))
            ->assertForbidden();
    }

    #[Test]
    public function no_se_inicia_un_proyecto_si_el_contrato_no_esta_activo(): void
    {
        $manager = $this->userWithRole('gerencia');
        $project = $this->project($this->hourBagContract(attributes: ['status' => ContractStatus::Draft]), null, ['status' => ProjectStatus::Planned]);

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/projects/{$project->id}/status", ['status' => 'active'])
            ->assertConflict()
            ->assertJsonPath('code', 'contract_not_active');
    }

    #[Test]
    public function no_se_cierra_un_proyecto_con_horas_sin_resolver(): void
    {
        $leader = $this->userWithRole('lider-de-proyecto');
        $consultant = $this->userWithRole('consultor');
        $project = $this->project($this->hourBagContract(), $leader);
        $current = $this->allocate($consultant, $project, 50, '2026-10-01');
        $future = $this->allocate($this->userWithRole('consultor'), $project, 50, '2026-11-01');

        $submitted = $this->entry($consultant, $project, TimeEntryStatus::Submitted);
        $draft = $this->entry($consultant, $project, TimeEntryStatus::Draft, ['work_date' => '2026-10-21']);

        $this->actingAs($leader, 'sanctum')
            ->patchJson("/api/v1/projects/{$project->id}/status", ['status' => 'closed'])
            ->assertConflict()
            ->assertJsonPath('code', 'pending_time_entries')
            ->assertJsonPath('message', fn (string $m) => str_contains($m, '1 registro enviado sin aprobar') && str_contains($m, '1 registro en borrador'));

        $submitted->forceFill(['status' => TimeEntryStatus::Approved])->save();
        $draft->delete();

        $this->patchJson("/api/v1/projects/{$project->id}/status", ['status' => 'closed'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'closed')
            ->assertJsonPath('data.next_statuses', []);

        // El equipo queda libre: la vigente termina hoy y la futura desaparece.
        $this->assertSame('2026-10-21', $current->fresh()->end_date->toDateString());
        $this->assertNull(Allocation::query()->find($future->id));

        $this->patchJson("/api/v1/projects/{$project->id}/status", ['status' => 'active'])
            ->assertConflict()
            ->assertJsonPath('code', 'invalid_transition');
    }

    #[Test]
    public function pausar_pide_motivo_y_el_lider_solo_cambia_el_estado_de_sus_proyectos(): void
    {
        $leader = $this->userWithRole('lider-de-proyecto');
        $mine = $this->project($this->hourBagContract(), $leader);
        $other = $this->project($this->hourBagContract(), $this->userWithRole('lider-de-proyecto'));

        $this->actingAs($leader, 'sanctum')
            ->patchJson("/api/v1/projects/{$mine->id}/status", ['status' => 'on_hold'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');

        $this->patchJson("/api/v1/projects/{$mine->id}/status", ['status' => 'on_hold', 'note' => 'El cliente pidió esperar a enero.'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'on_hold');

        $this->patchJson("/api/v1/projects/{$other->id}/status", ['status' => 'on_hold', 'note' => 'No es mío.'])
            ->assertForbidden();

        // Tampoco edita su propio presupuesto ni se cambia de líder.
        $this->putJson("/api/v1/projects/{$mine->id}", ['budget_hours' => 9999])->assertForbidden();
        $this->patchJson("/api/v1/projects/{$mine->id}/leader", ['leader_id' => $leader->id])->assertForbidden();

        $log = AuditLog::query()->where('action', 'project.status_changed')->sole();
        $this->assertSame('El cliente pidió esperar a enero.', $log->metadata['note']);
    }

    #[Test]
    public function los_cambios_quedan_en_la_auditoria_con_lo_que_cambio(): void
    {
        $manager = $this->userWithRole('gerencia');
        $newLeader = $this->userWithRole('lider-de-proyecto');
        $project = $this->project($this->hourBagContract(), null, ['budget_minutes' => 6000]);

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/v1/projects/{$project->id}", ['budget_hours' => 150, 'contract_id' => 99])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contract_id');

        $this->putJson("/api/v1/projects/{$project->id}", ['budget_hours' => 150, 'name' => 'Nombre nuevo'])
            ->assertOk()
            ->assertJsonPath('data.budget_minutes', 9000);

        $log = AuditLog::query()->where('action', 'project.updated')->sole();
        $this->assertEquals(['old' => 6000, 'new' => 9000], $log->metadata['changes']['budget_minutes']);

        $this->patchJson("/api/v1/projects/{$project->id}/leader", ['leader_id' => $newLeader->id])
            ->assertOk()
            ->assertJsonPath('data.leader.id', $newLeader->id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'project.leader_changed']);
    }

    #[Test]
    public function solo_se_elimina_un_proyecto_sin_horas(): void
    {
        $manager = $this->userWithRole('gerencia');
        $consultant = $this->userWithRole('consultor');
        $withTime = $this->project($this->hourBagContract());
        $this->entry($consultant, $withTime, TimeEntryStatus::Approved);
        $empty = $this->project($this->hourBagContract());
        $this->allocate($consultant, $empty, 30, '2026-11-01');

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/projects/{$withTime->id}")
            ->assertConflict()
            ->assertJsonPath('code', 'project_has_time_entries');

        $this->deleteJson("/api/v1/projects/{$empty->id}")->assertOk();
        $this->assertNull(Project::query()->find($empty->id));
        $this->assertSame(0, Allocation::query()->where('project_id', $empty->id)->count());

        // Subgerencia no elimina proyectos.
        $this->actingAs($this->userWithRole('subgerencia'), 'sanctum')
            ->deleteJson("/api/v1/projects/{$withTime->id}")
            ->assertForbidden();
    }

    #[Test]
    public function la_salud_se_calcula_con_las_horas_y_las_fechas(): void
    {
        $manager = $this->userWithRole('gerencia');
        $consultant = $this->userWithRole('consultor');

        // 10 h de presupuesto y 9,5 h consumidas: en riesgo.
        $risky = $this->project($this->hourBagContract(), null, ['budget_minutes' => 600]);
        $this->allocate($consultant, $risky, 20);
        $this->entry($consultant, $risky, TimeEntryStatus::Approved, ['minutes' => 420, 'work_date' => '2026-09-01']);
        $this->entry($consultant, $risky, TimeEntryStatus::Submitted, ['minutes' => 150, 'work_date' => '2026-09-02']);

        // Presupuesto superado y fecha de fin vencida.
        $late = $this->project($this->hourBagContract(), null, ['budget_minutes' => 60, 'end_date' => '2026-10-01']);
        $this->allocate($consultant, $late, 10);
        $this->entry($consultant, $late, TimeEntryStatus::Approved, ['minutes' => 120, 'work_date' => '2026-09-15']);

        // 100 h de presupuesto, 52 h consumidas y 32 h en las últimas 4
        // semanas: a ese ritmo las horas se acaban mucho antes de junio.
        $burning = $this->project($this->hourBagContract(), null, ['budget_minutes' => 6000, 'end_date' => '2027-06-30']);
        $this->allocate($consultant, $burning, 30);
        $this->entry($consultant, $burning, TimeEntryStatus::Approved, ['minutes' => 1200, 'work_date' => '2026-08-01']);
        $this->entry($consultant, $burning, TimeEntryStatus::Approved, ['minutes' => 480, 'work_date' => '2026-10-01']);
        $this->entry($consultant, $burning, TimeEntryStatus::Approved, ['minutes' => 480, 'work_date' => '2026-10-08']);
        $this->entry($consultant, $burning, TimeEntryStatus::Approved, ['minutes' => 480, 'work_date' => '2026-10-15']);
        $this->entry($consultant, $burning, TimeEntryStatus::Approved, ['minutes' => 480, 'work_date' => '2026-10-20']);

        $this->actingAs($manager, 'sanctum');

        $this->getJson("/api/v1/projects/{$risky->id}")
            ->assertOk()
            ->assertJsonPath('data.health.level', 'warning')
            ->assertJsonPath('data.health.flags.0.code', 'at_risk')
            ->assertJsonPath('data.health.consumed_minutes', 570)
            ->assertJsonPath('data.health.budget_ratio', 0.95);

        $codes = array_column($this->getJson("/api/v1/projects/{$late->id}")->json('data.health.flags'), 'code');
        $this->assertSame(['over_budget', 'overdue'], $codes);

        $burn = $this->getJson("/api/v1/projects/{$burning->id}")->assertOk()->json('data.health');
        // 1.920 min en 28 días → 480 min/semana; faltan 2.880 min → 42 días.
        $this->assertSame(480, $burn['weekly_burn_minutes']);
        $this->assertSame('2026-12-02', $burn['projected_exhaustion_date']);
        $this->assertSame(['exhausts_before_end'], array_column($burn['flags'], 'code'));
    }

    #[Test]
    public function sin_presupuesto_propio_el_proyecto_se_mide_contra_la_bolsa_del_contrato(): void
    {
        $consultant = $this->userWithRole('consultor');
        $contract = $this->hourBagContract(10); // 600 minutos para todos sus proyectos
        $first = $this->project($contract);
        $second = $this->project($contract);
        $this->allocate($consultant, $first, 10);
        $this->entry($consultant, $first, TimeEntryStatus::Approved, ['minutes' => 300, 'work_date' => '2026-09-01']);
        $this->entry($consultant, $second, TimeEntryStatus::Approved, ['minutes' => 270, 'work_date' => '2026-09-02']);

        $this->actingAs($this->userWithRole('gerencia'), 'sanctum')
            ->getJson("/api/v1/projects/{$first->id}")
            ->assertOk()
            ->assertJsonPath('data.health.budget_source', 'contract_bag')
            ->assertJsonPath('data.health.budget_minutes', 600)
            ->assertJsonPath('data.health.budget_consumed_minutes', 570)
            ->assertJsonPath('data.health.consumed_minutes', 300)
            ->assertJsonPath('data.health.flags.0.code', 'at_risk');

        // Una mensualidad no tiene bolsa: sin presupuesto no hay riesgo que medir.
        $this->getJson('/api/v1/projects/'.$this->project($this->monthlyContract())->id)
            ->assertOk()
            ->assertJsonPath('data.health.budget_source', null)
            ->assertJsonPath('data.health.budget_ratio', null);
    }

    #[Test]
    public function los_costos_del_proyecto_solo_los_ve_quien_tiene_permiso(): void
    {
        $consultant = $this->userWithRole('consultor', [], 60000);
        $project = $this->project($this->hourBagContract());
        $this->entry($consultant, $project, TimeEntryStatus::Approved, ['minutes' => 90, 'cost_rate' => 60000]);
        $this->entry($consultant, $project, TimeEntryStatus::Approved, ['minutes' => 30, 'cost_rate' => null]);

        $this->actingAs($this->userWithRole('gerencia'), 'sanctum')
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.financials.approved_cost', 90000)
            ->assertJsonPath('data.financials.uncosted_minutes', 30)
            ->assertJsonPath('data.financials.average_hourly_cost', 60000);

        $this->actingAs($this->userWithRole('comercial'), 'sanctum')
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.financials')
            ->assertJsonPath('data.can.view_financials', false);
    }

    #[Test]
    public function horas_por_consultor_y_semana(): void
    {
        $leader = $this->userWithRole('lider-de-proyecto');
        $ana = $this->userWithRole('consultor', ['name' => 'Ana']);
        $project = $this->project($this->hourBagContract(), $leader);

        $this->entry($ana, $project, TimeEntryStatus::Approved, ['work_date' => '2026-10-19', 'minutes' => 120]);
        $this->entry($ana, $project, TimeEntryStatus::Submitted, ['work_date' => '2026-10-21', 'minutes' => 60]);
        $this->entry($ana, $project, TimeEntryStatus::Rejected, ['work_date' => '2026-10-21', 'minutes' => 480]);
        $this->entry($ana, $project, TimeEntryStatus::Approved, ['work_date' => '2026-10-13', 'minutes' => 30]);

        $this->actingAs($leader, 'sanctum')
            ->getJson("/api/v1/projects/{$project->id}/hours?weeks=4")
            ->assertOk()
            ->assertJsonPath('data.weeks', ['2026-09-28', '2026-10-05', '2026-10-12', '2026-10-19'])
            ->assertJsonPath('data.people.0.weeks', [0, 0, 30, 180])
            ->assertJsonPath('data.people.0.approved_minutes', 150)
            ->assertJsonPath('data.people.0.pending_minutes', 60)
            ->assertJsonPath('data.totals', [0, 0, 30, 180]);

        $this->actingAs($this->userWithRole('lider-de-proyecto'), 'sanctum')
            ->getJson("/api/v1/projects/{$project->id}/hours")
            ->assertForbidden();
    }

    #[Test]
    public function el_selector_de_lideres_solo_muestra_quien_puede_liderar(): void
    {
        $manager = $this->userWithRole('gerencia', ['name' => 'Gerente']);
        $leader = $this->userWithRole('lider-de-proyecto', ['name' => 'Líder']);
        $consultant = $this->userWithRole('consultor', ['name' => 'Consultor']);
        $this->userWithRole('lider-de-proyecto', ['name' => 'Inactivo', 'is_active' => false]);

        $names = array_column($this->actingAs($manager, 'sanctum')->getJson('/api/v1/projects/leaders')->assertOk()->json('data'), 'name');

        $this->assertContains('Líder', $names);
        $this->assertContains('Gerente', $names);
        $this->assertNotContains('Consultor', $names);
        $this->assertNotContains('Inactivo', $names);

        $this->actingAs($consultant, 'sanctum')->getJson('/api/v1/projects/leaders')->assertForbidden();
    }

    #[Test]
    public function el_filtro_mis_proyectos_y_el_alcance_del_lider(): void
    {
        $manager = $this->userWithRole('gerencia');
        $leader = $this->userWithRole('lider-de-proyecto');
        $mine = $this->project($this->hourBagContract(), $manager);
        $theirs = $this->project($this->hourBagContract(), $leader);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/projects?mine=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->actingAs($leader, 'sanctum')
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $theirs->id)
            ->assertJsonPath('data.0.can.change_status', true)
            ->assertJsonPath('data.0.can.update', false);

        $this->getJson("/api/v1/projects/{$mine->id}")->assertForbidden();
    }
}
