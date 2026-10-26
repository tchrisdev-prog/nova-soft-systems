<?php

declare(strict_types=1);

namespace App\Services\Timesheets;

use App\Enums\TimeEntryStatus;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qué horas puede ver cada quien. La regla se aplica en la consulta (no
 * filtrando después en PHP), así nunca llega al navegador algo que no debía.
 *
 * - mine:   las propias (cualquiera que registre horas).
 * - review: las de los proyectos que la persona puede aprobar.
 * - all:    todas (timesheets.view o timesheets.approve_any).
 */
class TimeEntryQuery
{
    public const SCOPES = ['mine', 'review', 'all'];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function for(User $user, string $scope, array $filters = []): Builder
    {
        $query = TimeEntry::query();

        match ($scope) {
            'mine' => $query->where('user_id', $user->getKey()),
            'review' => $this->reviewScope($query, $user),
            'all' => $this->allScope($query, $user),
        };

        return $query
            ->when($filters['project_id'] ?? null, fn (Builder $q, $id) => $q->where('project_id', $id))
            ->when($filters['user_id'] ?? null, fn (Builder $q, $id) => $q->where('user_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('work_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('work_date', '<=', $to))
            ->when(isset($filters['is_after_hours']), fn (Builder $q) => $q->where('is_after_hours', (bool) $filters['is_after_hours']))
            ->when(isset($filters['is_on_call']), fn (Builder $q) => $q->where('is_on_call', (bool) $filters['is_on_call']))
            ->when(isset($filters['is_billable']), fn (Builder $q) => $q->where('is_billable', (bool) $filters['is_billable']));
    }

    public function canUseScope(User $user, string $scope): bool
    {
        return match ($scope) {
            'mine' => $user->hasPermission('timesheets.create'),
            'review' => $user->hasPermission('timesheets.approve') || $user->hasPermission('timesheets.approve_any'),
            'all' => $user->hasPermission('timesheets.view') || $user->hasPermission('timesheets.approve_any'),
            default => false,
        };
    }

    /**
     * Totales para las métricas del registro: una sola consulta agregada.
     *
     * @return array<string, int>
     */
    public function summary(Builder $query, bool $withCost): array
    {
        $approved = TimeEntryStatus::Approved->value;
        $submitted = TimeEntryStatus::Submitted->value;
        $draft = TimeEntryStatus::Draft->value;
        $rejected = TimeEntryStatus::Rejected->value;

        $row = (clone $query)->toBase()->selectRaw(
            'COUNT(*) AS entries, '.
            'COALESCE(SUM(minutes), 0) AS total_minutes, '.
            'COALESCE(SUM(CASE WHEN status = ? THEN minutes ELSE 0 END), 0) AS approved_minutes, '.
            'COALESCE(SUM(CASE WHEN status = ? THEN minutes ELSE 0 END), 0) AS submitted_minutes, '.
            'COALESCE(SUM(CASE WHEN status = ? THEN minutes ELSE 0 END), 0) AS draft_minutes, '.
            'COALESCE(SUM(CASE WHEN status = ? THEN minutes ELSE 0 END), 0) AS rejected_minutes, '.
            'COALESCE(SUM(CASE WHEN is_billable THEN minutes ELSE 0 END), 0) AS billable_minutes, '.
            'COALESCE(SUM(CASE WHEN is_after_hours THEN minutes ELSE 0 END), 0) AS after_hours_minutes, '.
            'COALESCE(SUM(CASE WHEN is_on_call THEN minutes ELSE 0 END), 0) AS on_call_minutes, '.
            'COALESCE(SUM(CASE WHEN status = ? THEN minutes * COALESCE(cost_rate, 0) ELSE 0 END), 0) AS cost_minutes',
            [$approved, $submitted, $draft, $rejected, $approved],
        )->reorder()->first();

        $summary = collect((array) $row)->except('cost_minutes')->map(fn ($v) => (int) $v)->all();

        if ($withCost) {
            $summary['approved_cost'] = intdiv((int) $row->cost_minutes, 60);
        }

        return $summary;
    }

    private function reviewScope(Builder $query, User $user): void
    {
        if ($user->hasPermission('timesheets.approve_any')) {
            $query->where('user_id', '!=', $user->getKey());

            return;
        }

        $query->where('user_id', '!=', $user->getKey())
            ->whereHas('project', fn (Builder $q) => $q->where('leader_id', $user->getKey()));
    }

    private function allScope(Builder $query, User $user): void
    {
        // Sin restricciones adicionales: el permiso ya se validó en canUseScope().
    }
}
