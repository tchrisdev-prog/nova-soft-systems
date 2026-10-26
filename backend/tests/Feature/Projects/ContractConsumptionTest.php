<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\TimeEntryStatus;
use App\Services\Contracts\ContractPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsFixtures;
use Tests\TestCase;

class ContractConsumptionTest extends TestCase
{
    use ProjectsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectsModule();
        $this->freezeBusinessTime();
    }

    #[Test]
    public function el_saldo_de_la_bolsa_cuenta_solo_horas_aprobadas_y_muestra_las_pendientes(): void
    {
        $contract = $this->hourBagContract(100, 20000000);
        $project = $this->project($contract);
        $consultant = $this->userWithRole('consultor');

        $this->entry($consultant, $project, TimeEntryStatus::Approved, ['minutes' => 600, 'cost_rate' => 60000]);
        $this->entry($consultant, $project, TimeEntryStatus::Approved, ['minutes' => 300, 'cost_rate' => 60000, 'is_after_hours' => true, 'work_date' => '2026-10-18']);
        $this->entry($consultant, $project, TimeEntryStatus::Submitted, ['minutes' => 120, 'work_date' => '2026-10-19']);
        $this->entry($consultant, $project, TimeEntryStatus::Draft, ['minutes' => 60, 'work_date' => '2026-10-21']);

        $this->actingAs($this->userWithRole('gerencia'), 'sanctum')
            ->getJson("/api/v1/contracts/{$contract->id}/consumption")
            ->assertOk()
            ->assertJsonPath('data.purchased_minutes', 6000)
            ->assertJsonPath('data.approved_minutes', 900)
            ->assertJsonPath('data.pending_minutes', 120)
            ->assertJsonPath('data.balance_minutes', 5100)
            ->assertJsonPath('data.after_hours_minutes', 300)
            ->assertJsonPath('data.cost', 900000)
            ->assertJsonPath('data.margin', 19100000);
    }

    #[Test]
    public function comercial_ve_el_consumo_pero_no_el_costo_ni_el_margen(): void
    {
        $contract = $this->monthlyContract(10000000, 40);
        app(ContractPeriods::class)->ensureFor($contract, CarbonImmutable::parse('2026-10-05'));

        $data = $this->actingAs($this->userWithRole('comercial'), 'sanctum')
            ->getJson("/api/v1/contracts/{$contract->id}/consumption")
            ->assertOk()
            ->json('data');

        $this->assertSame(10000000, $data['periods'][0]['fee_amount']);
        $this->assertArrayNotHasKey('cost', $data['periods'][0]);
        $this->assertArrayNotHasKey('margin', $data['periods'][0]);
    }

    #[Test]
    public function una_mensualidad_mide_cada_mes_por_separado_incluso_sin_horas_incluidas(): void
    {
        $contract = $this->monthlyContract(6500000, null, ['start_date' => '2026-09-01']);
        $periods = app(ContractPeriods::class);
        $periods->ensureFor($contract, CarbonImmutable::parse('2026-09-10'));
        $periods->ensureFor($contract, CarbonImmutable::parse('2026-10-10'));

        $project = $this->project($contract);
        $consultant = $this->userWithRole('consultor');
        $this->entry($consultant, $project, TimeEntryStatus::Approved, ['work_date' => '2026-09-30', 'minutes' => 240, 'cost_rate' => 90000]);
        $this->entry($consultant, $project, TimeEntryStatus::Approved, ['work_date' => '2026-10-01', 'minutes' => 60, 'cost_rate' => 90000, 'is_on_call' => true]);

        $data = $this->actingAs($this->userWithRole('gerencia'), 'sanctum')
            ->getJson("/api/v1/contracts/{$contract->id}/consumption")
            ->json('data');

        $this->assertSame(['2026-09', '2026-10'], array_column($data['periods'], 'period'));
        $this->assertSame([240, 60], array_column($data['periods'], 'approved_minutes'));
        $this->assertSame([null, null], array_column($data['periods'], 'included_minutes'));
        $this->assertSame([360000, 90000], array_column($data['periods'], 'cost'));
        $this->assertSame(60, $data['on_call_minutes']);
    }

    #[Test]
    public function abrir_los_meses_no_duplica_periodos(): void
    {
        $contract = $this->monthlyContract();

        $this->artisan('nova:contracts-renew')->expectsOutputToContain('Meses abiertos: 1.')->assertSuccessful();
        $this->artisan('nova:contracts-renew')->expectsOutputToContain('No había meses por abrir.')->assertSuccessful();

        $this->assertSame(1, $contract->periods()->count());
    }
}
