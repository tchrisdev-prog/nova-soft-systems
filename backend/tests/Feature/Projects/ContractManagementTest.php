<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\ContractStatus;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\ContractRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsFixtures;
use Tests\TestCase;

class ContractManagementTest extends TestCase
{
    use ProjectsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectsModule();
        $this->freezeBusinessTime();
    }

    private function hourBagPayload(array $overrides = []): array
    {
        return array_merge([
            'client_id' => $this->client()->id,
            'code' => 'ct-2026-001',
            'name' => 'Desarrollo del portal de clientes',
            'service_line' => 'custom_software',
            'billing_model' => 'hour_bag',
            'coverage' => 'business_hours',
            'start_date' => '2026-10-01',
            'initial_minutes' => 300 * 60,
            'initial_amount' => 54000000,
        ], $overrides);
    }

    #[Test]
    public function una_bolsa_de_horas_nace_en_borrador_con_su_movimiento_inicial_y_su_historial(): void
    {
        $sales = $this->userWithRole('comercial');

        $response = $this->actingAs($sales, 'sanctum')->postJson('/api/v1/contracts', $this->hourBagPayload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'CT-2026-001')
            ->assertJsonPath('data.status.value', 'draft')
            ->assertJsonPath('data.billing_model.label', 'Bolsa de horas')
            ->assertJsonPath('data.hour_bag.purchased_minutes', 18000)
            ->assertJsonPath('data.hour_bag.contract_value', 54000000);

        $contract = Contract::query()->findOrFail($response->json('data.id'));

        $this->assertSame(['created', 'hours_initial'], $contract->revisions()->orderBy('id')->pluck('event')->all());
        $this->assertSame($sales->id, $contract->revisions()->first()->actor_id);
        $this->assertTrue(AuditLog::query()->where('action', 'contract.created')->exists());
    }

    #[Test]
    public function cada_forma_de_cobro_exige_sus_propios_datos(): void
    {
        $this->actingAs($this->userWithRole('comercial'), 'sanctum');

        $this->postJson('/api/v1/contracts', $this->hourBagPayload(['initial_minutes' => null]))
            ->assertJsonValidationErrors(['initial_minutes']);

        $this->postJson('/api/v1/contracts', $this->hourBagPayload(['monthly_fee' => 5000000]))
            ->assertJsonValidationErrors(['monthly_fee']);

        $this->postJson('/api/v1/contracts', $this->hourBagPayload([
            'billing_model' => 'monthly_recurring', 'initial_minutes' => null, 'initial_amount' => null,
        ]))->assertJsonValidationErrors(['monthly_fee']);

        // Mensualidad sin horas incluidas (solo disponibilidad): es válida.
        $this->postJson('/api/v1/contracts', $this->hourBagPayload([
            'code' => 'CT-NUBE-01', 'service_line' => 'cloud_services', 'billing_model' => 'monthly_recurring',
            'coverage' => '24x7', 'initial_minutes' => null, 'initial_amount' => null, 'monthly_fee' => 6500000,
        ]))->assertCreated()->assertJsonPath('data.included_minutes_per_month', null);
    }

    #[Test]
    public function activar_una_mensualidad_abre_el_mes_en_curso_con_sus_condiciones(): void
    {
        $contract = $this->monthlyContract(12000000, 40, ['status' => ContractStatus::Draft, 'start_date' => '2026-09-01']);

        $this->actingAs($this->userWithRole('comercial'), 'sanctum')
            ->patchJson("/api/v1/contracts/{$contract->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'active');

        $period = $contract->periods()->sole();
        $this->assertSame('2026-10-01', $period->period_start->toDateString());
        $this->assertSame(12000000, $period->fee_amount);
        $this->assertSame(2400, $period->included_minutes);
        $this->assertSame(['status_changed', 'period_opened'], $contract->revisions()->orderBy('id')->pluck('event')->all());
    }

    #[Test]
    public function los_cambios_de_estado_siguen_un_flujo_y_suspender_exige_motivo(): void
    {
        $contract = $this->hourBagContract();
        $this->actingAs($this->userWithRole('gerencia'), 'sanctum');

        $this->patchJson("/api/v1/contracts/{$contract->id}/status", ['status' => 'suspended'])
            ->assertJsonValidationErrors(['note']);

        $this->patchJson("/api/v1/contracts/{$contract->id}/status", ['status' => 'finished'])->assertOk();

        $this->patchJson("/api/v1/contracts/{$contract->id}/status", ['status' => 'active'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_transition');

        $this->putJson("/api/v1/contracts/{$contract->id}", ['name' => 'Otro nombre'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'contract_closed');
    }

    #[Test]
    public function una_recarga_queda_registrada_sin_modificar_la_bolsa_original(): void
    {
        $contract = $this->hourBagContract(100, 18000000);
        $sales = $this->userWithRole('comercial');
        $this->actingAs($sales, 'sanctum');

        $this->postJson("/api/v1/contracts/{$contract->id}/hour-movements", [
            'type' => 'top_up', 'minutes' => 50 * 60, 'amount' => 9000000, 'reference_date' => '2026-10-15', 'note' => 'Orden de compra 4471',
        ])->assertCreated()->assertJsonPath('data.type.value', 'top_up');

        $this->getJson("/api/v1/contracts/{$contract->id}")
            ->assertJsonPath('data.hour_bag.purchased_minutes', 150 * 60)
            ->assertJsonPath('data.hour_bag.contract_value', 27000000);

        $this->getJson("/api/v1/contracts/{$contract->id}/hour-movements")->assertJsonCount(2, 'data');

        $this->getJson("/api/v1/contracts/{$contract->id}/history")
            ->assertOk()
            ->assertJsonPath('data.0.event', 'hours_added')
            ->assertJsonPath('data.0.actor.id', $sales->id)
            ->assertJsonPath('data.0.note', 'Orden de compra 4471')
            ->assertJsonPath('data.0.changes.minutes.new', 3000);
    }

    #[Test]
    public function un_ajuste_exige_motivo_y_no_puede_dejar_la_bolsa_en_negativo(): void
    {
        $contract = $this->hourBagContract(10);
        $this->actingAs($this->userWithRole('gerencia'), 'sanctum');

        $this->postJson("/api/v1/contracts/{$contract->id}/hour-movements", [
            'type' => 'adjustment', 'minutes' => -60, 'amount' => 0, 'reference_date' => '2026-10-15',
        ])->assertJsonValidationErrors(['note']);

        $this->postJson("/api/v1/contracts/{$contract->id}/hour-movements", [
            'type' => 'adjustment', 'minutes' => -11 * 60, 'amount' => 0, 'reference_date' => '2026-10-15', 'note' => 'Corrección de digitación',
        ])->assertStatus(422)->assertJsonPath('code', 'negative_hour_bag');

        $monthly = $this->monthlyContract();
        $this->postJson("/api/v1/contracts/{$monthly->id}/hour-movements", [
            'type' => 'top_up', 'minutes' => 600, 'amount' => 0, 'reference_date' => '2026-10-15',
        ])->assertStatus(422)->assertJsonPath('code', 'not_an_hour_bag');
    }

    #[Test]
    public function editar_un_contrato_guarda_el_antes_y_el_despues(): void
    {
        $contract = $this->monthlyContract(10000000, 40, ['coverage' => 'extended']);
        $this->actingAs($this->userWithRole('comercial'), 'sanctum');

        $this->putJson("/api/v1/contracts/{$contract->id}", [
            'coverage' => '24x7', 'monthly_fee' => 14000000, 'note' => 'El cliente amplía la cobertura por temporada alta.',
        ])->assertOk()->assertJsonPath('data.coverage.label', '24/7');

        $revision = $contract->revisions()->where('event', 'updated')->sole();
        $this->assertSame(['old' => 'extended', 'new' => '24x7'], $revision->changes['coverage']);
        $this->assertSame(['old' => 10000000, 'new' => 14000000], $revision->changes['monthly_fee']);
        $this->assertSame('El cliente amplía la cobertura por temporada alta.', $revision->note);

        $this->putJson("/api/v1/contracts/{$contract->id}", ['billing_model' => 'hour_bag'])
            ->assertJsonValidationErrors(['billing_model']);
    }

    #[Test]
    public function el_historial_no_se_puede_modificar_ni_borrar(): void
    {
        $contract = $this->hourBagContract();
        $this->actingAs($this->userWithRole('comercial'), 'sanctum')
            ->putJson("/api/v1/contracts/{$contract->id}", ['name' => 'Nombre corregido'])->assertOk();

        $revision = ContractRevision::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $revision->forceFill(['note' => 'cambiado'])->save();
    }

    #[Test]
    public function solo_se_eliminan_contratos_en_borrador_sin_proyectos(): void
    {
        $active = $this->hourBagContract();
        $draft = $this->hourBagContract(attributes: ['status' => ContractStatus::Draft]);
        $this->actingAs($this->userWithRole('gerencia'), 'sanctum');

        $this->deleteJson("/api/v1/contracts/{$active->id}")->assertStatus(409)->assertJsonPath('code', 'contract_in_use');
        $this->deleteJson("/api/v1/contracts/{$draft->id}")->assertOk();
        $this->assertModelMissing($draft);
        $this->assertTrue(AuditLog::query()->where('action', 'contract.deleted')->exists());
    }
}
