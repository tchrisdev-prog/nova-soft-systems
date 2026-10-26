<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Enums\ContractStatus;
use App\Enums\ProjectStatus;
use App\Enums\TimeEntryStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Support\CodeGenerator;
use App\Support\BusinessCalendar;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Crear, editar, cambiar de estado, cambiar de líder y eliminar proyectos.
 * Cada operación deja su línea en la auditoría con lo que cambió.
 */
class ProjectService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CodeGenerator $codes,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Datos validados por StoreProjectRequest.
     */
    public function create(User $actor, array $data): Project
    {
        $contract = Contract::query()->findOrFail($data['contract_id']);

        // Se puede preparar el proyecto mientras el contrato está en borrador
        // (así el equipo se organiza antes de la firma), pero no colgarlo de
        // un contrato suspendido, finalizado o cancelado.
        if (! in_array($contract->status, [ContractStatus::Draft, ContractStatus::Active], true)) {
            throw new BusinessRuleException(
                "El contrato {$contract->code} está {$contract->status->label()}. Solo se crean proyectos sobre contratos en borrador o activos.",
                'contract_not_open',
                422,
            );
        }

        $this->assertWithinContract($contract, $data['start_date'], $data['end_date'] ?? null);
        $leader = isset($data['leader_id']) ? $this->validLeader((int) $data['leader_id']) : null;
        $site = $this->activeSite((int) $data['site_id']);

        return DB::transaction(function () use ($actor, $data, $contract, $leader, $site) {
            $project = new Project(Arr::only($data, (new Project)->getFillable()));
            $project->forceFill([
                'contract_id' => $contract->getKey(),
                'code' => $this->codes->nextProjectCode((int) substr((string) $data['start_date'], 0, 4)),
                'status' => ProjectStatus::Planned,
                'leader_id' => $leader?->getKey(),
                'site_id' => $site->getKey(),
                'created_by' => $actor->getKey(),
            ])->save();

            $this->audit->log('project.created', $actor, $project, [
                'code' => $project->code,
                'contract' => $contract->code,
                'leader_id' => $leader?->getKey(),
            ]);

            return $project;
        });
    }

    /**
     * El contrato no se cambia: si el trabajo pasa a otro contrato, las horas
     * ya registradas quedarían descontadas del contrato equivocado.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Project $project, array $data): Project
    {
        $this->assertNotClosed($project);

        return DB::transaction(function () use ($actor, $project, $data) {
            $project->fill(Arr::only($data, $project->getFillable()));

            if (array_key_exists('site_id', $data)) {
                $project->forceFill(['site_id' => $this->activeSite((int) $data['site_id'])->getKey()]);
            }

            $this->assertWithinContract(
                $project->contract,
                $project->start_date->toDateString(),
                $project->end_date?->toDateString(),
            );

            $changes = $this->diff($project);

            if ($changes === []) {
                return $project;
            }

            $project->save();
            $this->audit->log('project.updated', $actor, $project, ['changes' => $changes]);

            return $project;
        });
    }

    public function changeStatus(User $actor, Project $project, ProjectStatus $next, ?string $note = null): Project
    {
        $current = $project->status;

        if ($current === $next) {
            return $project;
        }

        if (! $current->canTransitionTo($next)) {
            throw new BusinessRuleException(
                "Un proyecto {$current->label()} no puede pasar a {$next->label()}.",
                'invalid_transition',
                409,
            );
        }

        if ($next === ProjectStatus::Active && $project->contract->status !== ContractStatus::Active) {
            throw new BusinessRuleException(
                "El contrato {$project->contract->code} todavía no está activo. Actívalo antes de iniciar el proyecto.",
                'contract_not_active',
                409,
            );
        }

        return DB::transaction(function () use ($actor, $project, $current, $next, $note) {
            // Se bloquea el proyecto para que nadie envíe horas justo mientras
            // se revisa si quedan pendientes.
            Project::query()->whereKey($project->getKey())->lockForUpdate()->first();
            $ended = 0;

            if ($next === ProjectStatus::Closed) {
                $this->assertNoPendingTime($project);
                $ended = $this->releaseTeam($project);
            }

            $project->forceFill(['status' => $next])->save();

            $this->audit->log('project.status_changed', $actor, $project, array_filter([
                'from' => $current->value,
                'to' => $next->value,
                'note' => $note,
                'allocations_ended' => $ended ?: null,
            ], fn ($v) => $v !== null));

            return $project;
        });
    }

    public function changeLeader(User $actor, Project $project, int $leaderId): Project
    {
        $this->assertNotClosed($project);
        $leader = $this->validLeader($leaderId);
        $previous = $project->leader_id;

        if ($previous === $leader->getKey()) {
            return $project;
        }

        $project->forceFill(['leader_id' => $leader->getKey()])->save();
        $this->audit->log('project.leader_changed', $actor, $project, ['from' => $previous, 'to' => $leader->getKey()]);

        return $project->load('leader');
    }

    /**
     * Solo se elimina un proyecto creado por error: si ya tiene horas, esas
     * horas son la prueba de lo que se cobró y el proyecto se cierra.
     */
    public function delete(User $actor, Project $project): void
    {
        if ($project->timeEntries()->exists()) {
            throw new BusinessRuleException(
                'El proyecto ya tiene horas registradas. Ciérralo en lugar de eliminarlo.',
                'project_has_time_entries',
                409,
            );
        }

        DB::transaction(function () use ($actor, $project) {
            $allocations = $project->allocations()->count();
            $project->allocations()->delete();
            $project->delete();

            $this->audit->log('project.deleted', $actor, null, [
                'project_id' => $project->getKey(),
                'code' => $project->code,
                'name' => $project->name,
                'allocations_deleted' => $allocations,
            ]);
        });
    }

    /**
     * Las horas en borrador o enviadas tienen que resolverse antes del cierre:
     * cerrar con horas sin aprobar dejaría consumo del contrato en el limbo.
     */
    private function assertNoPendingTime(Project $project): void
    {
        $pending = $project->timeEntries()
            ->whereIn('status', [TimeEntryStatus::Draft->value, TimeEntryStatus::Submitted->value])
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        if ($pending->isEmpty()) {
            return;
        }

        $submitted = (int) ($pending[TimeEntryStatus::Submitted->value] ?? 0);
        $drafts = (int) ($pending[TimeEntryStatus::Draft->value] ?? 0);
        $parts = array_filter([
            $submitted ? "{$submitted} ".($submitted === 1 ? 'registro enviado' : 'registros enviados').' sin aprobar' : null,
            $drafts ? "{$drafts} ".($drafts === 1 ? 'registro' : 'registros').' en borrador' : null,
        ]);

        throw new BusinessRuleException(
            'No se puede cerrar el proyecto: tiene '.implode(' y ', $parts).'. Apruébalos o recházalos primero (los borradores debe enviarlos o borrarlos cada consultor).',
            'pending_time_entries',
            409,
        );
    }

    /**
     * Al cerrar, el equipo queda libre desde hoy. Las asignaciones que aún no
     * empezaban se borran: nunca ocuparon a nadie.
     */
    private function releaseTeam(Project $project): int
    {
        $today = BusinessCalendar::today()->toDateString();

        $future = $project->allocations()->where('start_date', '>', $today)->delete();
        $open = $project->allocations()
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>', $today))
            ->update(['end_date' => $today, 'updated_at' => now()]);

        return $future + $open;
    }

    private function validLeader(int $userId): User
    {
        $leader = User::query()->whereKey($userId)->where('is_active', true)->first();

        // El permiso se revisa aquí, no en la interfaz: alguien sin
        // projects.lead no vería el proyecto ni podría aprobar sus horas.
        if (! $leader || ! $leader->hasPermission('projects.lead')) {
            throw new BusinessRuleException(
                'El líder debe ser un usuario activo con el permiso para liderar proyectos.',
                'invalid_leader',
                422,
            );
        }

        return $leader;
    }

    private function activeSite(int $siteId): Site
    {
        $site = Site::query()->whereKey($siteId)->where('is_active', true)->first();

        if (! $site) {
            throw new BusinessRuleException('La sede no existe o está inactiva.', 'invalid_site', 422);
        }

        return $site;
    }

    private function assertWithinContract(Contract $contract, string $start, ?string $end): void
    {
        $contractStart = $contract->start_date->toDateString();
        $contractEnd = $contract->end_date?->toDateString();

        if ($start < $contractStart) {
            throw new BusinessRuleException("El proyecto no puede empezar antes que el contrato ({$contractStart}).", 'outside_contract', 422);
        }

        if ($contractEnd !== null && ($end === null || $end > $contractEnd)) {
            throw new BusinessRuleException("El contrato termina el {$contractEnd}; el proyecto debe terminar a más tardar ese día.", 'outside_contract', 422);
        }
    }

    private function assertNotClosed(Project $project): void
    {
        if ($project->status === ProjectStatus::Closed) {
            throw new BusinessRuleException('El proyecto está cerrado y ya no se puede modificar.', 'project_closed', 409);
        }
    }

    /** @return array<string, array{old: mixed, new: mixed}> */
    private function diff(Project $project): array
    {
        $changes = [];

        foreach ($project->getDirty() as $field => $value) {
            $old = $project->getOriginal($field);
            $changes[$field] = [
                'old' => $old instanceof \BackedEnum ? $old->value : ($old instanceof \DateTimeInterface ? $old->format('Y-m-d') : $old),
                'new' => $project->getAttribute($field) instanceof \BackedEnum ? $project->getAttribute($field)->value
                    : ($project->getAttribute($field) instanceof \DateTimeInterface ? $project->getAttribute($field)->format('Y-m-d') : $value),
            ];
        }

        // CalendarDate puede marcar como sucio un valor que en realidad no cambió.
        return array_filter($changes, fn (array $c) => $c['old'] !== $c['new']);
    }
}
