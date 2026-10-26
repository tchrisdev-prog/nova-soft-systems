<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Enums\TimeEntryStatus;
use App\Models\AuditLog;
use App\Services\Contracts\ContractPeriods;
use App\Services\Contracts\ContractsExport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsFixtures;
use Tests\TestCase;

class ContractAlertsAndExportTest extends TestCase
{
    use ProjectsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectsModule();
        $this->freezeBusinessTime(); // 21 de octubre de 2026
    }

    private function alertsOf(int $contractId): array
    {
        return array_column($this->getJson("/api/v1/contracts/{$contractId}")->assertOk()->json('data.alerts'), 'code');
    }

    #[Test]
    public function avisa_cuando_una_bolsa_se_esta_agotando_o_ya_se_excedio(): void
    {
        $low = $this->hourBagContract(10);
        $this->entry($this->userWithRole('consultor'), $this->project($low), TimeEntryStatus::Approved, ['minutes' => 510]); // 85 %

        $exceeded = $this->hourBagContract(10);
        $this->entry($this->userWithRole('consultor'), $this->project($exceeded), TimeEntryStatus::Approved, ['minutes' => 660]);

        $healthy = $this->hourBagContract(10);
        $this->entry($this->userWithRole('consultor'), $this->project($healthy), TimeEntryStatus::Approved, ['minutes' => 120]);

        $this->actingAs($this->userWithRole('comercial'), 'sanctum');

        $this->assertSame(['bag_low'], $this->alertsOf($low->id));
        $this->assertSame(['bag_exceeded'], $this->alertsOf($exceeded->id));
        $this->assertSame([], $this->alertsOf($healthy->id));

        $this->getJson("/api/v1/contracts/{$low->id}")
            ->assertJsonPath('data.alerts.0.message', 'Bolsa al 85 %: quedan 1,5 h')
            ->assertJsonPath('data.consumption.balance_minutes', 90);
    }

    #[Test]
    public function avisa_el_excedente_del_mes_y_los_contratos_por_vencer(): void
    {
        $monthly = $this->monthlyContract(10000000, 2, ['end_date' => '2026-11-10']);
        app(ContractPeriods::class)->ensureFor($monthly, CarbonImmutable::parse('2026-10-05'));
        $project = $this->project($monthly);
        // El último día del mes también cuenta (antes se perdía en SQLite).
        $this->entry($this->userWithRole('consultor'), $project, TimeEntryStatus::Approved, ['work_date' => '2026-10-31', 'minutes' => 180]);

        $expired = $this->hourBagContract(100, attributes: ['end_date' => '2026-10-01']);

        $this->actingAs($this->userWithRole('comercial'), 'sanctum');

        $this->assertSame(['monthly_overage', 'expiring'], $this->alertsOf($monthly->id));
        $this->getJson("/api/v1/contracts/{$monthly->id}")
            ->assertJsonPath('data.consumption.approved_minutes', 180)
            ->assertJsonPath('data.alerts.1.message', 'Vence en 20 días');
        $this->assertSame(['expired'], $this->alertsOf($expired->id));
    }

    #[Test]
    public function los_ingresos_y_costos_del_listado_solo_los_ve_quien_tiene_permiso(): void
    {
        $contract = $this->hourBagContract(100, 20000000);
        $this->entry($this->userWithRole('consultor'), $this->project($contract), TimeEntryStatus::Approved, ['minutes' => 600, 'cost_rate' => 60000]);

        $this->actingAs($this->userWithRole('comercial'), 'sanctum');
        $this->assertArrayNotHasKey('financials', $this->getJson('/api/v1/contracts')->json('data.0'));

        $this->actingAs($this->userWithRole('gerencia'), 'sanctum')
            ->getJson('/api/v1/contracts')
            ->assertJsonPath('data.0.financials.revenue_to_date', 20000000)
            ->assertJsonPath('data.0.financials.approved_cost', 600000)
            ->assertJsonPath('data.0.financials.margin', 19400000);
    }

    #[Test]
    public function exporta_a_excel_sin_columnas_financieras_para_comercial(): void
    {
        $contract = $this->hourBagContract(100, 20000000, ['name' => '=HIPERVINCULO("http://malo")']);
        $this->entry($this->userWithRole('consultor'), $this->project($contract), TimeEntryStatus::Approved, ['minutes' => 90, 'cost_rate' => 60000]);

        $response = $this->actingAs($this->userWithRole('comercial'), 'sanctum')->get('/api/v1/contracts/export');
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename="contratos-2026-10-21.csv"', $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = explode("\n", trim(substr($csv, 3)));
        $this->assertStringStartsWith('Código;Cliente;Contrato', $lines[0]);
        $this->assertStringNotContainsString('Costo interno', $lines[0]);
        // La fórmula se exporta como texto, nunca como fórmula.
        $this->assertStringContainsString(';"\'=HIPERVINCULO(', $lines[1]);
        $this->assertStringContainsString(';1,50;', $lines[1]);

        $log = AuditLog::query()->where('action', 'contracts.exported')->sole();
        $this->assertSame(1, $log->metadata['rows']);
        $this->assertFalse($log->metadata['with_financials']);

        $withMoney = $this->actingAs($this->userWithRole('gerencia'), 'sanctum')->get('/api/v1/contracts/export')->streamedContent();
        $this->assertStringContainsString('"Costo interno (COP)";"Margen (COP)"', $withMoney);
        $this->assertStringContainsString(';20000000;90000;19910000', $withMoney);
    }

    #[Test]
    public function el_consultor_no_puede_exportar(): void
    {
        $this->actingAs($this->userWithRole('consultor'), 'sanctum')->get('/api/v1/contracts/export')->assertForbidden();
    }

    #[Test]
    public function neutraliza_formulas_en_las_celdas(): void
    {
        foreach (['=1+1', '+57 300', '-2', '@SUMA', "\tx"] as $value) {
            $this->assertStringStartsWith("'", ContractsExport::safeCell($value));
        }

        $this->assertSame('Banco Andino', ContractsExport::safeCell('Banco Andino'));
        $this->assertSame('42', ContractsExport::safeCell(42));
    }

    #[Test]
    public function el_sistema_genera_el_codigo_del_contrato_por_ano(): void
    {
        $client = $this->client();
        $this->actingAs($this->userWithRole('comercial'), 'sanctum');
        $base = [
            'client_id' => $client->id, 'name' => 'Soporte', 'service_line' => 'infrastructure_support',
            'billing_model' => 'monthly_recurring', 'coverage' => 'business_hours', 'monthly_fee' => 5000000,
        ];

        $this->postJson('/api/v1/contracts', $base + ['start_date' => '2026-11-01'])->assertCreated()->assertJsonPath('data.code', 'CT-2026-001');
        $this->postJson('/api/v1/contracts', $base + ['start_date' => '2026-12-01'])->assertJsonPath('data.code', 'CT-2026-002');
        $this->postJson('/api/v1/contracts', $base + ['start_date' => '2027-01-15'])->assertJsonPath('data.code', 'CT-2027-001');
    }

    #[Test]
    public function una_asignacion_que_empieza_hoy_permite_registrar_horas_hoy(): void
    {
        $consultant = $this->userWithRole('consultor');
        $project = $this->project($this->hourBagContract());
        $this->allocate($consultant, $project, 50, '2026-10-21');

        $this->actingAs($consultant, 'sanctum')->postJson('/api/v1/time-entries', [
            'project_id' => $project->id, 'work_date' => '2026-10-21', 'minutes' => 60, 'description' => 'Primer día en el proyecto',
        ])->assertCreated();
    }
}
