<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\ProjectStatus;
use App\Enums\TimeEntryStatus;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsFixtures;
use Tests\TestCase;

class TimeEntryWorkflowTest extends TestCase
{
    use ProjectsFixtures, RefreshDatabase;

    private User $leader;

    private User $consultant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectsModule();
        $this->freezeBusinessTime();

        $this->leader = $this->userWithRole('lider-de-proyecto');
        $this->consultant = $this->userWithRole('consultor', hourlyCost: 70000);
        $this->project = $this->project($this->hourBagContract(), $this->leader);
        $this->allocate($this->consultant, $this->project, 80);
    }

    private function log(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->consultant, 'sanctum')->postJson('/api/v1/time-entries', array_merge([
            'project_id' => $this->project->id,
            'work_date' => '2026-10-21',
            'minutes' => 120,
            'description' => 'Ajustes al módulo de pagos',
        ], $overrides));
    }

    #[Test]
    public function el_consultor_registra_horas_en_su_proyecto_incluso_fuera_de_horario(): void
    {
        $this->log()->assertCreated()->assertJsonPath('data.status.value', 'draft')->assertJsonPath('data.hours', 2);

        // Domingo en la madrugada, atendiendo una alarma de un contrato 24/7.
        $this->log(['work_date' => '2026-10-18', 'minutes' => 90, 'is_after_hours' => true, 'is_on_call' => true])
            ->assertCreated()
            ->assertJsonPath('data.is_after_hours', true)
            ->assertJsonPath('data.is_on_call', true);
    }

    #[Test]
    public function no_registra_horas_donde_no_esta_asignado_ni_en_fechas_no_permitidas(): void
    {
        $other = $this->project($this->hourBagContract());

        $this->log(['project_id' => $other->id])->assertForbidden()->assertJsonPath('code', 'not_allocated');
        $this->log(['work_date' => '2026-10-22'])->assertStatus(422)->assertJsonPath('code', 'future_date');
        $this->log(['work_date' => '2026-08-01'])->assertStatus(422)->assertJsonPath('code', 'date_too_old');
        $this->log(['minutes' => 50])->assertJsonValidationErrors(['minutes']);

        $this->project->forceFill(['status' => ProjectStatus::OnHold])->save();
        $this->log()->assertStatus(422)->assertJsonPath('code', 'project_not_active');
    }

    #[Test]
    public function un_dia_no_puede_superar_24_horas(): void
    {
        $this->log(['minutes' => 1440 - 60])->assertCreated();
        $this->log(['minutes' => 120])->assertStatus(422)->assertJsonPath('code', 'daily_limit_exceeded');
        $this->log(['minutes' => 60])->assertCreated();
    }

    #[Test]
    public function el_lider_aprueba_las_horas_de_su_equipo_y_queda_la_foto_del_costo(): void
    {
        $id = $this->log()->json('data.id');
        $this->postJson('/api/v1/time-entries/submit', ['ids' => [$id]])->assertOk()->assertJsonPath('data.count', 1);

        $this->actingAs($this->leader, 'sanctum')
            ->getJson('/api/v1/time-entries?scope=review&status=submitted')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.can.review', true);

        $this->postJson('/api/v1/time-entries/approve', ['ids' => [$id]])->assertOk();

        $entry = $this->consultant->timeEntries()->sole();
        $this->assertSame(TimeEntryStatus::Approved, $entry->status);
        $this->assertSame($this->leader->id, $entry->reviewed_by);
        $this->assertSame(70000, $entry->cost_rate);
        $this->assertTrue(AuditLog::query()->where('action', 'time_entries.approved')->exists());

        // Lo aprobado ya no se toca.
        $this->actingAs($this->consultant, 'sanctum')
            ->putJson("/api/v1/time-entries/{$id}", ['minutes' => 60])
            ->assertForbidden();
        $this->deleteJson("/api/v1/time-entries/{$id}")->assertForbidden();
    }

    #[Test]
    public function nadie_aprueba_sus_propias_horas_ni_las_de_proyectos_ajenos(): void
    {
        $ownEntry = $this->entry($this->leader, $this->project);
        $this->actingAs($this->leader, 'sanctum')
            ->postJson('/api/v1/time-entries/approve', ['ids' => [$ownEntry->id]])
            ->assertForbidden()
            ->assertJsonPath('code', 'self_review');

        $foreign = $this->project($this->hourBagContract(), $this->userWithRole('lider-de-proyecto'));
        $foreignEntry = $this->entry($this->consultant, $foreign);
        $this->postJson('/api/v1/time-entries/approve', ['ids' => [$foreignEntry->id]])->assertForbidden();

        // Gerencia y Subgerencia aprueban en cualquier proyecto (pero tampoco las propias).
        foreach (['gerencia', 'subgerencia'] as $slug) {
            $approver = $this->userWithRole($slug);
            $entry = $this->entry($this->leader, $foreign);
            $this->actingAs($approver, 'sanctum')->postJson('/api/v1/time-entries/approve', ['ids' => [$entry->id]])->assertOk();
        }
    }

    #[Test]
    public function aprobar_un_lote_es_todo_o_nada(): void
    {
        $ok = $this->entry($this->consultant, $this->project);
        $draft = $this->entry($this->consultant, $this->project, TimeEntryStatus::Draft);

        $this->actingAs($this->leader, 'sanctum')
            ->postJson('/api/v1/time-entries/approve', ['ids' => [$ok->id, $draft->id]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_submitted');

        $this->assertSame(TimeEntryStatus::Submitted, $ok->fresh()->status);
    }

    #[Test]
    public function rechazar_exige_motivo_y_al_corregir_vuelve_a_borrador(): void
    {
        $entry = $this->entry($this->consultant, $this->project);

        $this->actingAs($this->leader, 'sanctum')
            ->postJson('/api/v1/time-entries/reject', ['ids' => [$entry->id]])
            ->assertJsonValidationErrors(['reason']);

        $this->postJson('/api/v1/time-entries/reject', ['ids' => [$entry->id], 'reason' => 'Falta el número del ticket.'])->assertOk();
        $this->assertSame(TimeEntryStatus::Rejected, $entry->fresh()->status);

        $this->actingAs($this->consultant, 'sanctum')
            ->putJson("/api/v1/time-entries/{$entry->id}", ['description' => 'Ticket 8812: ajustes al módulo de pagos'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'draft')
            ->assertJsonPath('data.rejection_reason', null);
    }

    #[Test]
    public function el_resumen_suma_por_estado_y_por_tipo_de_hora(): void
    {
        $this->entry($this->consultant, $this->project, TimeEntryStatus::Approved, ['minutes' => 120]);
        $this->entry($this->consultant, $this->project, TimeEntryStatus::Submitted, ['minutes' => 60, 'is_after_hours' => true]);
        $this->entry($this->consultant, $this->project, TimeEntryStatus::Draft, ['minutes' => 30, 'is_billable' => false]);

        $this->actingAs($this->consultant, 'sanctum')
            ->getJson('/api/v1/time-entries/summary')
            ->assertOk()
            ->assertJsonPath('data.entries', 3)
            ->assertJsonPath('data.total_minutes', 210)
            ->assertJsonPath('data.approved_minutes', 120)
            ->assertJsonPath('data.submitted_minutes', 60)
            ->assertJsonPath('data.billable_minutes', 180)
            ->assertJsonPath('data.after_hours_minutes', 60);
    }

    #[Test]
    public function el_consultor_ve_los_proyectos_donde_puede_registrar_horas(): void
    {
        $this->project($this->hourBagContract()); // no asignado

        $this->actingAs($this->consultant, 'sanctum')
            ->getJson('/api/v1/time-entries/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->project->id);
    }
}
