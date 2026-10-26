<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Enums\BillingModel;
use App\Enums\ProjectStatus;
use App\Enums\TimeEntryStatus;
use App\Models\Project;
use App\Support\BusinessCalendar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La "salud" de un proyecto no es un estado que alguien marque: se calcula
 * cada vez con las horas y las fechas, así nunca queda desactualizada.
 *
 * Señales:
 * - over_budget: lo consumido (aprobado + enviado) ya pasó el presupuesto
 *   (el del proyecto o, si no tiene, la bolsa de horas de su contrato).
 * - at_risk: lo consumido llegó al umbral de riesgo (90 % por defecto).
 * - exhausts_before_end: al ritmo de las últimas 4 semanas, las horas se
 *   acaban antes de la fecha de fin.
 * - overdue: pasó la fecha de fin y el proyecto sigue abierto.
 * - no_team: está planeado o en curso y no tiene a nadie asignado.
 *
 * Se calcula para una página completa con unas pocas consultas, no una por
 * proyecto.
 */
class ProjectHealth
{
    public const BURN_WINDOW_DAYS = 28;

    /**
     * @param  Collection<int, Project>|Project  $projects
     */
    public function attach(Collection|Project $projects): void
    {
        $list = $projects instanceof Project ? collect([$projects]) : $projects;

        if ($list->isEmpty()) {
            return;
        }

        $ids = $list->map(fn (Project $p) => $p->getKey())->all();
        $today = BusinessCalendar::today();
        $windowStart = $today->subDays(self::BURN_WINDOW_DAYS - 1)->toDateString();

        $minutes = $this->minutes('project_id', $ids, $windowStart);

        $teams = DB::table('allocations')
            ->whereIn('project_id', $ids)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today->toDateString()))
            ->groupBy('project_id')
            ->selectRaw('project_id, COUNT(DISTINCT user_id) AS people')
            ->pluck('people', 'project_id');

        // Un proyecto sin presupuesto propio que cuelga de una bolsa de horas
        // se mide contra la bolsa: lo que importa es cuánto le queda al
        // contrato, sumando todos sus proyectos.
        $bagContracts = DB::table('contracts')
            ->whereIn('id', $list->whereNull('budget_minutes')->pluck('contract_id')->unique()->all())
            ->where('billing_model', BillingModel::HourBag->value)
            ->pluck('id')
            ->all();

        $bags = DB::table('contract_hour_movements')
            ->whereIn('contract_id', $bagContracts)
            ->groupBy('contract_id')
            ->selectRaw('contract_id, SUM(minutes) AS minutes')
            ->pluck('minutes', 'contract_id');

        $byContract = $bagContracts === [] ? collect() : $this->minutes(
            'contract_id',
            $bagContracts,
            $windowStart,
            fn ($q) => $q->join('projects', 'projects.id', '=', 'time_entries.project_id'),
        );

        foreach ($list as $project) {
            $own = $this->figures($minutes->get($project->getKey()));
            $basis = ['source' => $project->budget_minutes ? 'project' : null, 'budget' => $project->budget_minutes] + $own;

            if (! $project->budget_minutes && in_array((int) $project->contract_id, array_map('intval', $bagContracts), true)) {
                $basis = ['source' => 'contract_bag', 'budget' => (int) ($bags[$project->contract_id] ?? 0) ?: null]
                    + $this->figures($byContract->get($project->contract_id));
            }

            $project->setRelation('health', $this->evaluate($project, $own, $basis, (int) ($teams[$project->getKey()] ?? 0)));
        }
    }

    /**
     * Minutos por estado agrupados por proyecto o por contrato.
     *
     * @param  list<int>  $ids
     * @return Collection<int|string, object>
     */
    private function minutes(string $groupBy, array $ids, string $windowStart, ?callable $join = null): Collection
    {
        $column = $groupBy === 'contract_id' ? 'projects.contract_id' : 'time_entries.project_id';
        $query = DB::table('time_entries');

        if ($join) {
            $join($query);
        }

        return $query
            ->whereIn($column, $ids)
            ->groupBy($column)
            ->selectRaw("{$column} AS group_id")
            ->selectRaw('SUM(CASE WHEN time_entries.status = ? THEN minutes ELSE 0 END) AS approved', [TimeEntryStatus::Approved->value])
            ->selectRaw('SUM(CASE WHEN time_entries.status = ? THEN minutes ELSE 0 END) AS submitted', [TimeEntryStatus::Submitted->value])
            ->selectRaw('SUM(CASE WHEN time_entries.status = ? THEN minutes ELSE 0 END) AS draft', [TimeEntryStatus::Draft->value])
            ->selectRaw('SUM(CASE WHEN time_entries.status != ? AND work_date >= ? THEN minutes ELSE 0 END) AS recent', [TimeEntryStatus::Rejected->value, $windowStart])
            ->get()
            ->keyBy('group_id');
    }

    /** @return array{approved: int, submitted: int, draft: int, recent: int} */
    private function figures(?object $row): array
    {
        return [
            'approved' => (int) ($row->approved ?? 0),
            'submitted' => (int) ($row->submitted ?? 0),
            'draft' => (int) ($row->draft ?? 0),
            'recent' => (int) ($row->recent ?? 0),
        ];
    }

    /**
     * @param  array{approved: int, submitted: int, draft: int, recent: int}  $own  Horas del proyecto.
     * @param  array{source: string|null, budget: int|null, approved: int, submitted: int, draft: int, recent: int}  $basis  Contra qué se mide.
     * @return array<string, mixed>
     */
    private function evaluate(Project $project, array $own, array $basis, int $team): array
    {
        $today = BusinessCalendar::today();
        $budget = $basis['budget'];
        $consumed = $basis['approved'] + $basis['submitted'];
        $recent = $basis['recent'];
        $ratio = $budget ? $consumed / $budget : null;
        $open = $project->status !== ProjectStatus::Closed;
        $flags = [];

        $exhaustion = null;
        if ($budget && $recent > 0 && $consumed < $budget) {
            // Días que alcanzan las horas restantes al ritmo reciente, en
            // aritmética entera (redondeando hacia arriba) para no depender
            // de decimales.
            $days = intdiv(($budget - $consumed) * self::BURN_WINDOW_DAYS + $recent - 1, $recent);
            $exhaustion = $today->addDays($days)->toDateString();
        }

        if ($open && $ratio !== null) {
            if ($ratio > 1) {
                $flags[] = $this->flag('over_budget', 'Presupuesto superado', 'danger');
            } elseif ($ratio >= (float) config('nova.projects.risk_threshold', 0.9)) {
                $flags[] = $this->flag('at_risk', 'Cerca del límite de horas', 'warning');
            }
        }

        $end = $project->end_date?->toDateString();

        if ($open && $exhaustion !== null && $end !== null && $exhaustion < $end && ($ratio ?? 0) <= 1) {
            $flags[] = $this->flag('exhausts_before_end', 'Las horas se acaban antes del cierre', 'warning');
        }

        if ($open && $end !== null && $end < $today->toDateString()) {
            $flags[] = $this->flag('overdue', 'Pasó la fecha de fin', 'danger');
        }

        if (in_array($project->status, [ProjectStatus::Planned, ProjectStatus::Active], true) && $team === 0) {
            $flags[] = $this->flag('no_team', 'Sin equipo asignado', 'warning');
        }

        $level = collect($flags)->contains('severity', 'danger') ? 'danger' : ($flags ? 'warning' : 'ok');

        return [
            'level' => $level,
            'flags' => $flags,
            // Horas del proyecto.
            'approved_minutes' => $own['approved'],
            'submitted_minutes' => $own['submitted'],
            'draft_minutes' => $own['draft'],
            'consumed_minutes' => $own['approved'] + $own['submitted'],
            'weekly_burn_minutes' => intdiv($own['recent'] * 7 + intdiv(self::BURN_WINDOW_DAYS, 2), self::BURN_WINDOW_DAYS),
            // Contra qué se mide el riesgo: el presupuesto propio o la bolsa
            // del contrato (que comparten todos sus proyectos).
            'budget_source' => $basis['source'],
            'budget_minutes' => $budget,
            'budget_consumed_minutes' => $budget ? $consumed : null,
            'budget_ratio' => $ratio !== null ? round($ratio, 4) : null,
            'projected_exhaustion_date' => $exhaustion,
            'team_size' => $team,
        ];
    }

    /** @return array{code: string, label: string, severity: string} */
    private function flag(string $code, string $label, string $severity): array
    {
        return ['code' => $code, 'label' => $label, 'severity' => $severity];
    }
}
