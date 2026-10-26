<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Enums\ProjectStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Allocation;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\BusinessCalendar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Asignación de consultores. La sobreasignación no se prohíbe (a veces es una
 * decisión consciente durante una urgencia), pero nunca pasa en silencio:
 * hay que confirmarla y queda en la auditoría.
 */
class AllocationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{user_id: int, allocation_percent: int, start_date: string, end_date?: string|null, role_label?: string|null}  $data
     */
    public function assign(User $actor, Project $project, array $data, bool $confirmOverallocation = false): Allocation
    {
        $this->assertProjectOpen($project);

        $consultant = User::query()->whereKey($data['user_id'])->where('is_active', true)->first();

        if (! $consultant) {
            throw new BusinessRuleException('El consultor no existe o su cuenta está desactivada.', 'inactive_user', 422);
        }

        return DB::transaction(function () use ($actor, $project, $consultant, $data, $confirmOverallocation) {
            // Serializa las asignaciones de la misma persona.
            User::query()->whereKey($consultant->getKey())->lockForUpdate()->first();

            $peak = $this->peakAllocation($consultant, $data['start_date'], $data['end_date'] ?? null) + (int) $data['allocation_percent'];

            if ($peak > 100 && ! $confirmOverallocation) {
                throw new BusinessRuleException(
                    "Con esta asignación {$consultant->name} quedaría al {$peak} % de su capacidad. Confirma si es intencional.",
                    'overallocation',
                    409,
                );
            }

            $allocation = new Allocation([
                'role_label' => $data['role_label'] ?? null,
                'allocation_percent' => (int) $data['allocation_percent'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
            ]);
            $allocation->forceFill([
                'project_id' => $project->getKey(),
                'user_id' => $consultant->getKey(),
                'created_by' => $actor->getKey(),
            ])->save();

            $this->audit->log('allocation.created', $actor, $allocation, [
                'project' => $project->code,
                'consultant_id' => $consultant->getKey(),
                'percent' => $allocation->allocation_percent,
                'peak_percent' => $peak,
                'overallocated' => $peak > 100,
            ]);

            return $allocation;
        });
    }

    /**
     * Cambiar el %, la fecha de fin o el rol. La fecha de inicio no se mueve:
     * si la persona entra en otra fecha, se termina esta asignación y se crea
     * otra, así el historial dice la verdad.
     *
     * @param  array{allocation_percent?: int, end_date?: string|null, role_label?: string|null}  $data
     */
    public function update(User $actor, Allocation $allocation, array $data, bool $confirmOverallocation = false): Allocation
    {
        $this->assertProjectOpen($allocation->project);

        return DB::transaction(function () use ($actor, $allocation, $data, $confirmOverallocation) {
            User::query()->whereKey($allocation->user_id)->lockForUpdate()->first();

            $before = [
                'allocation_percent' => $allocation->allocation_percent,
                'end_date' => $allocation->end_date?->toDateString(),
                'role_label' => $allocation->role_label,
            ];

            $allocation->fill(array_intersect_key($data, array_flip(['allocation_percent', 'end_date', 'role_label'])));
            $end = $allocation->end_date?->toDateString();

            if ($end !== null && $end < $allocation->start_date->toDateString()) {
                throw new BusinessRuleException('La fecha de fin no puede ser anterior al inicio de la asignación.', 'invalid_dates', 422);
            }

            $peak = $this->peakAllocation($allocation->user, $allocation->start_date->toDateString(), $end, $allocation->getKey())
                + $allocation->allocation_percent;

            // Solo se pide confirmar si el cambio sube la carga: acortar o
            // bajar el % de alguien sobreasignado siempre se permite.
            $grows = $allocation->allocation_percent > $before['allocation_percent']
                || ($before['end_date'] !== null && ($end === null || $end > $before['end_date']));

            if ($peak > 100 && $grows && ! $confirmOverallocation) {
                throw new BusinessRuleException(
                    "Con este cambio {$allocation->user->name} quedaría al {$peak} % de su capacidad. Confirma si es intencional.",
                    'overallocation',
                    409,
                );
            }

            $after = [
                'allocation_percent' => $allocation->allocation_percent,
                'end_date' => $end,
                'role_label' => $allocation->role_label,
            ];
            $changes = [];
            foreach ($after as $field => $value) {
                if ($before[$field] !== $value) {
                    $changes[$field] = ['old' => $before[$field], 'new' => $value];
                }
            }

            if ($changes === []) {
                return $allocation;
            }

            $allocation->save();
            $this->audit->log('allocation.updated', $actor, $allocation, [
                'project' => $allocation->project->code,
                'consultant_id' => $allocation->user_id,
                'changes' => $changes,
                'peak_percent' => $peak,
                'overallocated' => $peak > 100,
            ]);

            return $allocation;
        });
    }

    /**
     * Termina la asignación en una fecha (hoy si no se indica). Es la forma
     * normal de sacar a alguien de un proyecto: sus horas siguen ahí.
     */
    public function end(User $actor, Allocation $allocation, ?string $date = null): Allocation
    {
        $this->assertProjectOpen($allocation->project);
        $date ??= BusinessCalendar::today()->toDateString();

        if ($date < $allocation->start_date->toDateString()) {
            throw new BusinessRuleException(
                'La asignación aún no empieza. Si fue un error, elimínala.',
                'allocation_not_started',
                422,
            );
        }

        if ($allocation->end_date !== null && $allocation->end_date->toDateString() <= $date) {
            return $allocation;
        }

        $previous = $allocation->end_date?->toDateString();
        $allocation->forceFill(['end_date' => $date])->save();

        $this->audit->log('allocation.ended', $actor, $allocation, [
            'project' => $allocation->project->code,
            'consultant_id' => $allocation->user_id,
            'end_date' => ['old' => $previous, 'new' => $date],
        ]);

        return $allocation;
    }

    /**
     * Borrar solo sirve para corregir un error: si la persona ya registró
     * horas en el proyecto, la asignación se termina en lugar de borrarse.
     */
    public function remove(User $actor, Allocation $allocation): void
    {
        $this->assertProjectOpen($allocation->project);

        $hasTime = TimeEntry::query()
            ->where('project_id', $allocation->project_id)
            ->where('user_id', $allocation->user_id)
            ->exists();

        if ($hasTime) {
            throw new BusinessRuleException(
                'Esta persona ya registró horas en el proyecto. Termina la asignación en lugar de eliminarla.',
                'allocation_has_time',
                409,
            );
        }

        $allocation->delete();
        $this->audit->log('allocation.deleted', $actor, null, [
            'allocation_id' => $allocation->getKey(),
            'project' => $allocation->project->code,
            'consultant_id' => $allocation->user_id,
            'percent' => $allocation->allocation_percent,
        ]);
    }

    /**
     * El mayor % asignado a la persona en cualquier día del rango. Basta con
     * revisar los días en que empieza alguna asignación: es ahí donde la
     * suma puede subir.
     */
    public function peakAllocation(User $consultant, string $from, ?string $to, ?int $exceptId = null): int
    {
        /** @var Collection<int, Allocation> $allocations */
        $allocations = Allocation::query()
            ->where('user_id', $consultant->getKey())
            ->overlapping($from, $to)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            // Un proyecto cerrado ya no ocupa a nadie.
            ->whereHas('project', fn ($q) => $q->where('status', '!=', ProjectStatus::Closed->value))
            ->get(['start_date', 'end_date', 'allocation_percent']);

        if ($allocations->isEmpty()) {
            return 0;
        }

        $checkpoints = $allocations
            ->map(fn (Allocation $a) => max($a->start_date->toDateString(), $from))
            ->push($from)
            ->unique();

        return (int) $checkpoints->map(fn (string $day) => $allocations
            ->filter(fn (Allocation $a) => $a->start_date->toDateString() <= $day && ($a->end_date === null || $a->end_date->toDateString() >= $day))
            ->sum('allocation_percent'))
            ->max();
    }

    private function assertProjectOpen(Project $project): void
    {
        if ($project->status === ProjectStatus::Closed) {
            throw new BusinessRuleException('El proyecto está cerrado: su equipo ya no se puede modificar.', 'project_closed', 409);
        }
    }
}
