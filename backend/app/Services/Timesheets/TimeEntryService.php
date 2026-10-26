<?php

declare(strict_types=1);

namespace App\Services\Timesheets;

use App\Enums\ContractStatus;
use App\Enums\TimeEntryStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Allocation;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\BusinessCalendar;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Registro de horas. El servicio de la empresa es 24/7, así que se puede
 * registrar cualquier día y a cualquier hora; lo que se controla es que las
 * horas sean de alguien que de verdad está en el proyecto, que el día no
 * pase de 24 horas y que lo aprobado ya no se pueda tocar.
 */
class TimeEntryService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{project_id: int, work_date: string, minutes: int, description: string, is_billable?: bool, is_after_hours?: bool, is_on_call?: bool}  $data
     */
    public function create(User $actor, array $data): TimeEntry
    {
        $project = Project::query()->with('contract')->findOrFail($data['project_id']);
        $this->assertCanLogTime($actor, $project, $data['work_date']);

        return DB::transaction(function () use ($actor, $project, $data) {
            $this->assertDailyLimit($actor, $data['work_date'], (int) $data['minutes']);

            $entry = new TimeEntry(Arr::except($data, ['project_id']));
            $entry->forceFill([
                'user_id' => $actor->getKey(),
                'project_id' => $project->getKey(),
                'status' => TimeEntryStatus::Draft,
            ])->save();

            return $entry;
        });
    }

    /**
     * Corregir un registro rechazado lo devuelve a borrador: el consultor lo
     * revisa y lo vuelve a enviar cuando esté listo.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, TimeEntry $entry, array $data): TimeEntry
    {
        $project = isset($data['project_id']) && (int) $data['project_id'] !== $entry->project_id
            ? Project::query()->with('contract')->findOrFail($data['project_id'])
            : $entry->project()->with('contract')->firstOrFail();

        $workDate = $data['work_date'] ?? $entry->work_date->toDateString();
        $this->assertCanLogTime($actor, $project, $workDate);

        return DB::transaction(function () use ($actor, $entry, $project, $data, $workDate) {
            $minutes = (int) ($data['minutes'] ?? $entry->minutes);
            $this->assertDailyLimit($actor, $workDate, $minutes, ignoreId: $entry->getKey());

            $entry->fill(Arr::except($data, ['project_id']));
            $entry->forceFill([
                'project_id' => $project->getKey(),
                'status' => TimeEntryStatus::Draft,
                'rejection_reason' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();

            return $entry;
        });
    }

    public function delete(TimeEntry $entry): void
    {
        $entry->delete();
    }

    /**
     * Envía a revisión los borradores (o rechazados corregidos) del usuario.
     *
     * @param  list<int>  $ids
     */
    public function submit(User $actor, array $ids): int
    {
        return DB::transaction(function () use ($actor, $ids) {
            $entries = $this->lockEntries($ids);

            foreach ($entries as $entry) {
                if (! Gate::forUser($actor)->allows('submit', $entry)) {
                    throw new BusinessRuleException('Solo puedes enviar tus propios registros en borrador o rechazados.', 'not_submittable', 422);
                }
            }

            $entries->each(fn (TimeEntry $entry) => $entry->forceFill([
                'status' => TimeEntryStatus::Submitted,
                'submitted_at' => now(),
            ])->save());

            return $entries->count();
        });
    }

    /**
     * Aprueba un lote completo o ninguno: si una sola hora no se puede
     * aprobar, no se aprueba nada y se explica cuál fue el problema.
     *
     * @param  list<int>  $ids
     */
    public function approve(User $actor, array $ids): int
    {
        return DB::transaction(function () use ($actor, $ids) {
            $entries = $this->lockEntries($ids)->load(['project', 'user.staffProfile']);
            $this->assertReviewable($actor, $entries);

            $minutes = 0;

            foreach ($entries as $entry) {
                $entry->forceFill([
                    'status' => TimeEntryStatus::Approved,
                    'reviewed_by' => $actor->getKey(),
                    'reviewed_at' => now(),
                    'rejection_reason' => null,
                    // Foto del costo de la hora: los aumentos futuros no
                    // cambian la rentabilidad de lo que ya se aprobó.
                    'cost_rate' => $entry->user->staffProfile?->hourly_cost,
                ])->save();

                $minutes += $entry->minutes;
            }

            $this->audit->log('time_entries.approved', $actor, null, [
                'count' => $entries->count(),
                'minutes' => $minutes,
                'ids' => $entries->modelKeys(),
            ]);

            return $entries->count();
        });
    }

    /**
     * @param  list<int>  $ids
     */
    public function reject(User $actor, array $ids, string $reason): int
    {
        return DB::transaction(function () use ($actor, $ids, $reason) {
            $entries = $this->lockEntries($ids)->load('project');
            $this->assertReviewable($actor, $entries);

            $entries->each(fn (TimeEntry $entry) => $entry->forceFill([
                'status' => TimeEntryStatus::Rejected,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save());

            $this->audit->log('time_entries.rejected', $actor, null, [
                'count' => $entries->count(),
                'ids' => $entries->modelKeys(),
                'reason' => $reason,
            ]);

            return $entries->count();
        });
    }

    /**
     * Reglas para poder registrar horas en un proyecto en una fecha.
     */
    private function assertCanLogTime(User $actor, Project $project, string $workDate): void
    {
        if (! $project->status->acceptsTime()) {
            throw new BusinessRuleException("El proyecto está {$project->status->label()} y no admite registro de horas.", 'project_not_active', 422);
        }

        if ($project->contract->status !== ContractStatus::Active) {
            throw new BusinessRuleException('El contrato de este proyecto no está activo.', 'contract_not_active', 422);
        }

        $date = BusinessCalendar::parseDate($workDate);
        $today = BusinessCalendar::today();
        $maxBack = (int) config('nova.timesheets.max_backdate_days', 31);

        if ($date->gt($today)) {
            throw new BusinessRuleException('No se pueden registrar horas en fechas futuras.', 'future_date', 422);
        }

        if ($date->lt($today->subDays($maxBack))) {
            throw new BusinessRuleException("Solo se pueden registrar horas de los últimos {$maxBack} días. Para fechas anteriores, habla con tu líder.", 'date_too_old', 422);
        }

        $allocated = Allocation::query()
            ->where('project_id', $project->getKey())
            ->where('user_id', $actor->getKey())
            ->activeOn($workDate)
            ->exists();

        if (! $allocated && ! $project->isLedBy($actor)) {
            throw new BusinessRuleException('No estás asignado a este proyecto en esa fecha.', 'not_allocated', 403);
        }
    }

    /**
     * Un día tiene 24 horas, también para el soporte 24/7. Se bloquea la fila
     * del usuario para que dos registros simultáneos no superen el límite.
     */
    private function assertDailyLimit(User $actor, string $workDate, int $minutes, ?int $ignoreId = null): void
    {
        User::query()->whereKey($actor->getKey())->lockForUpdate()->first();

        $alreadyLogged = (int) TimeEntry::query()
            ->where('user_id', $actor->getKey())
            ->whereDate('work_date', $workDate)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->sum('minutes');

        if ($alreadyLogged + $minutes > 24 * 60) {
            throw new BusinessRuleException('Con este registro superarías 24 horas en el mismo día.', 'daily_limit_exceeded', 422);
        }
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, TimeEntry>
     */
    private function lockEntries(array $ids): Collection
    {
        $entries = TimeEntry::query()->whereKey($ids)->lockForUpdate()->get();

        if ($entries->count() !== count(array_unique($ids))) {
            throw new BusinessRuleException('Uno o más registros no existen.', 'not_found', 404);
        }

        return $entries;
    }

    /**
     * @param  Collection<int, TimeEntry>  $entries
     */
    private function assertReviewable(User $actor, Collection $entries): void
    {
        foreach ($entries as $entry) {
            if ($entry->isOwnedBy($actor)) {
                throw new BusinessRuleException('No puedes aprobar ni rechazar tus propias horas.', 'self_review', 403);
            }

            if (! Gate::forUser($actor)->allows('review', $entry)) {
                throw new AuthorizationException;
            }

            if ($entry->status !== TimeEntryStatus::Submitted) {
                throw new BusinessRuleException('Solo se revisan registros enviados. Alguno ya fue revisado o sigue en borrador.', 'not_submitted', 409);
            }
        }
    }
}
