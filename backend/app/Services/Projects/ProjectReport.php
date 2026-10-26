<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Enums\TimeEntryStatus;
use App\Models\Project;
use App\Models\User;
use App\Support\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Las cifras del detalle de un proyecto: horas por consultor y semana, y el
 * costo interno de lo aprobado (solo para quien tiene projects.financials).
 */
class ProjectReport
{
    /**
     * Horas de las últimas $weeks semanas (incluida la actual) por persona.
     * Los rechazos no cuentan: no se van a cobrar.
     *
     * @return array<string, mixed>
     */
    public function hoursByWeek(Project $project, int $weeks): array
    {
        $currentMonday = BusinessCalendar::today()->startOfWeek(CarbonImmutable::MONDAY);
        $first = $currentMonday->subWeeks($weeks - 1);
        $keys = [];

        for ($i = 0; $i < $weeks; $i++) {
            $keys[] = $first->addWeeks($i)->toDateString();
        }

        $rows = DB::table('time_entries')
            ->where('project_id', $project->getKey())
            ->whereBetween('work_date', [$first->toDateString(), $currentMonday->addDays(6)->toDateString()])
            ->where('status', '!=', TimeEntryStatus::Rejected->value)
            ->groupBy('user_id', 'work_date', 'status')
            ->selectRaw('user_id, work_date, status, SUM(minutes) AS minutes')
            ->get();

        $people = [];
        foreach ($rows as $row) {
            $week = BusinessCalendar::parseDate(substr((string) $row->work_date, 0, 10))->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            $id = (int) $row->user_id;
            $people[$id] ??= ['weeks' => array_fill_keys($keys, 0), 'approved' => 0, 'pending' => 0];
            $people[$id]['weeks'][$week] += (int) $row->minutes;
            $people[$id][$row->status === TimeEntryStatus::Approved->value ? 'approved' : 'pending'] += (int) $row->minutes;
        }

        $names = User::withTrashed()->whereKey(array_keys($people))->pluck('name', 'id');

        $result = collect($people)
            ->map(fn (array $p, int $id) => [
                'user' => ['id' => $id, 'name' => $names[$id] ?? "#{$id}"],
                'weeks' => array_values($p['weeks']),
                'total_minutes' => array_sum($p['weeks']),
                'approved_minutes' => $p['approved'],
                'pending_minutes' => $p['pending'],
            ])
            ->sortBy('user.name')
            ->values()
            ->all();

        return [
            'weeks' => $keys,
            'people' => $result,
            'totals' => array_map(fn (string $k) => array_sum(array_map(fn ($p) => $p['weeks'][$k], $people)), $keys),
        ];
    }

    /**
     * Costo interno de las horas aprobadas con la tarifa congelada al aprobar.
     * Si alguna hora se aprobó sin costo cargado, se informa en vez de
     * suponer un valor.
     *
     * @return array{approved_cost: int, costed_minutes: int, uncosted_minutes: int, average_hourly_cost: int|null}
     */
    public function financials(Project $project): array
    {
        $row = DB::table('time_entries')
            ->where('project_id', $project->getKey())
            ->where('status', TimeEntryStatus::Approved->value)
            ->selectRaw('SUM(CASE WHEN cost_rate IS NOT NULL THEN minutes * cost_rate ELSE 0 END) AS cost_x60')
            ->selectRaw('SUM(CASE WHEN cost_rate IS NOT NULL THEN minutes ELSE 0 END) AS costed')
            ->selectRaw('SUM(CASE WHEN cost_rate IS NULL THEN minutes ELSE 0 END) AS uncosted')
            ->first();

        $cost = (int) round(((int) ($row->cost_x60 ?? 0)) / 60);
        $costed = (int) ($row->costed ?? 0);

        return [
            'approved_cost' => $cost,
            'costed_minutes' => $costed,
            'uncosted_minutes' => (int) ($row->uncosted ?? 0),
            'average_hourly_cost' => $costed > 0 ? (int) round($cost / ($costed / 60)) : null,
        ];
    }
}
