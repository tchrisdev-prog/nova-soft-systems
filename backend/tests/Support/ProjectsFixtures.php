<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\BillingModel;
use App\Enums\ClientSector;
use App\Enums\ContractStatus;
use App\Enums\Coverage;
use App\Enums\HourMovementType;
use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Enums\ServiceLine;
use App\Enums\TimeEntryStatus;
use App\Models\Allocation;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractHourMovement;
use App\Models\Project;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffProfile;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Projects\ModuleSetup;
use Carbon\CarbonImmutable;

/**
 * Escenarios para las pruebas del módulo de proyectos. Crea los datos
 * directamente (sin pasar por la API) para que cada prueba se concentre en
 * la regla que quiere verificar.
 */
trait ProjectsFixtures
{
    /** Miércoles 21 de octubre de 2026, 3:00 p. m. en Bogotá. */
    protected function freezeBusinessTime(string $moment = '2026-10-21 15:00:00'): CarbonImmutable
    {
        $now = CarbonImmutable::parse($moment, 'America/Bogota');
        $this->travelTo($now);

        return $now;
    }

    protected function setUpProjectsModule(): void
    {
        $this->seedCatalog();
        app(ModuleSetup::class)->run();
    }

    protected function site(string $code = 'medellin'): Site
    {
        return Site::query()->where('code', $code)->firstOrFail();
    }

    /** Usuario con una de las plantillas de rol (gerencia, comercial, lider-de-proyecto...). */
    protected function userWithRole(string $slug, array $attributes = [], ?int $hourlyCost = 80000): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::query()->where('slug', $slug)->firstOrFail()->id, ['created_at' => now()]);

        $profile = new StaffProfile(['position' => 'Consultor']);
        $profile->forceFill([
            'user_id' => $user->id,
            'site_id' => $this->site()->id,
            'weekly_capacity_minutes' => 42 * 60,
            'hourly_cost' => $hourlyCost,
        ])->save();

        return $user->fresh();
    }

    protected function client(array $attributes = []): Client
    {
        static $sequence = 0;
        $sequence++;

        $client = new Client(['name' => "Cliente {$sequence}", 'sector' => ClientSector::Financial->value]);
        $client->forceFill(array_merge([
            'code' => "CLI-{$sequence}",
            'site_id' => $this->site()->id,
            'is_active' => true,
        ], $attributes))->save();

        return $client;
    }

    protected function hourBagContract(int $hours = 100, int $amount = 18000000, array $attributes = []): Contract
    {
        $contract = $this->contract(BillingModel::HourBag, $attributes);

        $movement = new ContractHourMovement;
        $movement->forceFill([
            'contract_id' => $contract->id,
            'type' => HourMovementType::Initial,
            'minutes' => $hours * 60,
            'amount' => $amount,
            'reference_date' => $contract->start_date->toDateString(),
            'created_at' => now(),
        ])->save();

        return $contract;
    }

    protected function monthlyContract(int $fee = 10000000, ?int $includedHours = 40, array $attributes = []): Contract
    {
        return $this->contract(BillingModel::MonthlyRecurring, array_merge([
            'monthly_fee' => $fee,
            'included_minutes_per_month' => $includedHours === null ? null : $includedHours * 60,
        ], $attributes));
    }

    protected function contract(BillingModel $billing, array $attributes = []): Contract
    {
        static $sequence = 0;
        $sequence++;

        $contract = new Contract([
            'name' => "Contrato {$sequence}",
            'service_line' => ServiceLine::CustomSoftware->value,
            'coverage' => Coverage::BusinessHours->value,
            'start_date' => '2026-01-01',
        ]);
        $contract->forceFill(array_merge([
            'client_id' => $attributes['client_id'] ?? $this->client()->id,
            'code' => "CT-{$sequence}",
            'billing_model' => $billing,
            'status' => ContractStatus::Active,
        ], $attributes))->save();

        return $contract->fresh();
    }

    protected function project(Contract $contract, ?User $leader = null, array $attributes = []): Project
    {
        static $sequence = 0;
        $sequence++;

        $project = new Project([
            'name' => "Proyecto {$sequence}",
            'kind' => $contract->isHourBag() ? ProjectKind::Project->value : ProjectKind::OngoingService->value,
            'start_date' => '2026-01-01',
        ]);
        $project->forceFill(array_merge([
            'contract_id' => $contract->id,
            'code' => "PRY-{$sequence}",
            'status' => ProjectStatus::Active,
            'leader_id' => $leader?->id,
            'site_id' => $this->site()->id,
        ], $attributes))->save();

        return $project->fresh();
    }

    protected function allocate(User $user, Project $project, int $percent = 50, string $from = '2026-01-01', ?string $to = null): Allocation
    {
        $allocation = new Allocation(['allocation_percent' => $percent, 'start_date' => $from, 'end_date' => $to]);
        $allocation->forceFill(['user_id' => $user->id, 'project_id' => $project->id])->save();

        return $allocation;
    }

    protected function entry(User $user, Project $project, TimeEntryStatus $status = TimeEntryStatus::Submitted, array $attributes = []): TimeEntry
    {
        $entry = new TimeEntry(array_merge([
            'work_date' => '2026-10-20',
            'minutes' => 120,
            'description' => 'Trabajo de prueba',
        ], array_intersect_key($attributes, array_flip(['work_date', 'minutes', 'description', 'is_billable', 'is_after_hours', 'is_on_call']))));
        $entry->forceFill(array_merge([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'status' => $status,
        ], array_diff_key($attributes, array_flip(['work_date', 'minutes', 'description', 'is_billable', 'is_after_hours', 'is_on_call']))))->save();

        return $entry->fresh();
    }
}
