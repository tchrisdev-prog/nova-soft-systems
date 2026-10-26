<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Enums\ProjectStatus;
use App\Enums\StaffSpecialty;
use App\Enums\TimeEntryStatus;
use App\Models\Allocation;
use App\Models\StaffProfile;
use App\Support\BusinessCalendar;
use App\Support\ColombianHolidays;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Capacidad de los consultores semana a semana.
 *
 * - Capacidad: la jornada semanal del perfil (42 h por defecto), menos los
 *   festivos de Colombia que caen entre lunes y viernes. Una semana con un
 *   festivo tiene 4/5 de la capacidad.
 * - Asignado: lo que comprometen las asignaciones, día hábil por día hábil
 *   (una asignación que empieza un miércoles solo cuenta desde el miércoles).
 * - Real: las horas registradas esa semana (enviadas, aprobadas o en
 *   borrador), para comparar lo planeado con lo que de verdad pasa.
 *
 * Todo sale de tres consultas (perfiles, asignaciones y horas agrupadas) sin
 * importar cuántas personas o semanas se pidan.
 */
class CapacityService
{
    public const HORIZONS = [4, 8, 12];

    /** Estados de proyecto que ocupan capacidad: un proyecto cerrado ya no. */
    private const OCCUPYING = [ProjectStatus::Planned, ProjectStatus::Active, ProjectStatus::OnHold];

    /**
     * @param  array{site_id?: int|null, specialty?: string|null, search?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function weekly(int $weeks, array $filters = []): array
    {
        $weekList = $this->weeks($weeks);
        $from = $weekList[0]['start'];
        $to = end($weekList)['end'];

        $profiles = $this->profiles($filters)->get();
        $userIds = $profiles->pluck('user_id')->all();
        $allocations = $this->allocations($userIds, $from->toDateString(), $to->toDateString())->groupBy('user_id');
        $actual = $this->actualMinutes($userIds, $from->toDateString(), $to->toDateString());

        $people = $profiles->map(function (StaffProfile $profile) use ($weekList, $allocations, $actual) {
            $mine = $allocations->get($profile->user_id, collect());
            $daily = $profile->weekly_capacity_minutes / 5;

            return [
                'user' => ['id' => $profile->user->id, 'name' => $profile->user->name],
                'position' => $profile->position,
                'specialty' => $profile->specialty ? ['value' => $profile->specialty->value, 'label' => $profile->specialty->label()] : null,
                'site' => ['id' => $profile->site->id, 'name' => $profile->site->name],
                'weekly_capacity_minutes' => $profile->weekly_capacity_minutes,
                'weeks' => array_map(function (array $week) use ($mine, $daily, $actual, $profile) {
                    $capacity = (int) round($daily * count($week['working_days']));
                    $allocated = 0.0;
                    $projects = [];

                    foreach ($week['working_days'] as $day) {
                        foreach ($mine as $allocation) {
                            if ($this->activeOn($allocation, $day)) {
                                $allocated += $daily * $allocation->allocation_percent / 100;
                                $projects[$allocation->project_id] ??= [
                                    'id' => $allocation->project->id,
                                    'code' => $allocation->project->code,
                                    'name' => $allocation->project->name,
                                    'percent' => $allocation->allocation_percent,
                                ];
                            }
                        }
                    }

                    $allocated = (int) round($allocated);

                    return [
                        'capacity_minutes' => $capacity,
                        'allocated_minutes' => $allocated,
                        'free_minutes' => max(0, $capacity - $allocated),
                        'percent' => $capacity > 0 ? round($allocated / $capacity, 4) : null,
                        'actual_minutes' => (int) ($actual[$profile->user_id][$week['key']] ?? 0),
                        'projects' => array_values($projects),
                    ];
                }, $weekList),
            ];
        })->values();

        return [
            'weeks' => array_map(fn (array $w) => [
                'key' => $w['key'],
                'start' => $w['start']->toDateString(),
                'end' => $w['end']->toDateString(),
                'working_days' => count($w['working_days']),
                'holidays' => $w['holidays'],
            ], $weekList),
            'people' => $people->all(),
            'summary' => $this->summary($people),
        ];
    }

    /**
     * Quién tiene el % pedido libre durante todo el rango. Se usa el día más
     * cargado del rango: si un solo día no alcanza, la persona no sirve.
     *
     * @param  array{site_id?: int|null, specialty?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function available(int $percent, string $from, string $to, array $filters = []): array
    {
        $profiles = $this->profiles($filters)->get();
        $allocations = $this->allocations($profiles->pluck('user_id')->all(), $from, $to)->groupBy('user_id');
        $start = BusinessCalendar::parseDate($from);
        $end = BusinessCalendar::parseDate($to);

        return $profiles
            ->map(function (StaffProfile $profile) use ($allocations, $start, $end) {
                $mine = $allocations->get($profile->user_id, collect());
                $peak = 0;

                // Solo cambia la carga en los días en que empieza una asignación.
                $checkpoints = $mine->map(fn (Allocation $a) => CarbonImmutable::parse($a->start_date->toDateString(), BusinessCalendar::timezone())->max($start))
                    ->push($start)
                    ->filter(fn (CarbonImmutable $d) => $d->lte($end));

                foreach ($checkpoints as $day) {
                    $load = $mine->filter(fn (Allocation $a) => $this->activeOn($a, $day))->sum('allocation_percent');
                    $peak = max($peak, $load);
                }

                return [
                    'user' => ['id' => $profile->user->id, 'name' => $profile->user->name],
                    'position' => $profile->position,
                    'specialty' => $profile->specialty ? ['value' => $profile->specialty->value, 'label' => $profile->specialty->label()] : null,
                    'site' => ['id' => $profile->site->id, 'name' => $profile->site->name],
                    'peak_percent' => (int) $peak,
                    'free_percent' => max(0, 100 - (int) $peak),
                    'projects' => $mine->map(fn (Allocation $a) => [
                        'code' => $a->project->code,
                        'name' => $a->project->name,
                        'percent' => $a->allocation_percent,
                    ])->unique('code')->values()->all(),
                ];
            })
            ->filter(fn (array $person) => $person['free_percent'] >= $percent)
            ->sortBy([['free_percent', 'desc'], ['user.name', 'asc']])
            ->values()
            ->take(50)
            ->all();
    }

    /**
     * Semanas de lunes a domingo desde la semana en curso.
     *
     * @return list<array{key: string, start: CarbonImmutable, end: CarbonImmutable, working_days: list<CarbonImmutable>, holidays: list<array{date: string, name: string}>}>
     */
    public function weeks(int $count, ?CarbonImmutable $from = null): array
    {
        $monday = ($from ?? BusinessCalendar::today())->startOfWeek(CarbonImmutable::MONDAY);
        $weeks = [];

        for ($i = 0; $i < $count; $i++) {
            $start = $monday->addWeeks($i);
            $working = [];
            $holidays = [];

            for ($d = 0; $d < 5; $d++) {
                $day = $start->addDays($d);

                if ($name = ColombianHolidays::name($day)) {
                    $holidays[] = ['date' => $day->toDateString(), 'name' => $name];
                } else {
                    $working[] = $day;
                }
            }

            $weeks[] = [
                'key' => $start->toDateString(),
                'start' => $start,
                'end' => $start->addDays(6),
                'working_days' => $working,
                'holidays' => $holidays,
            ];
        }

        return $weeks;
    }

    /** @param  array{site_id?: int|null, specialty?: string|null, search?: string|null}  $filters */
    private function profiles(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return StaffProfile::query()
            ->with(['user', 'site'])
            ->whereHas('user', fn (Builder $q) => $q->where('is_active', true)
                ->when($search !== '', fn (Builder $u) => $u->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])))
            ->when($filters['site_id'] ?? null, fn (Builder $q, $site) => $q->where('staff_profiles.site_id', $site))
            ->when($filters['specialty'] ?? null, fn (Builder $q, $specialty) => $q->where('staff_profiles.specialty', $specialty))
            ->join('users', 'users.id', '=', 'staff_profiles.user_id')
            // La relación user incluye eliminados (para el historial); aquí no sirven.
            ->whereNull('users.deleted_at')
            ->orderBy('users.name')
            ->select('staff_profiles.*');
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, Allocation>
     */
    private function allocations(array $userIds, string $from, string $to): Collection
    {
        return Allocation::query()
            ->whereIn('user_id', $userIds)
            ->overlapping($from, $to)
            ->whereHas('project', fn (Builder $q) => $q->whereIn('status', array_map(fn ($s) => $s->value, self::OCCUPYING)))
            ->with('project:id,code,name,status')
            ->get();
    }

    /**
     * Minutos registrados por persona y semana (sin contar los rechazados).
     * Se agrupa por día en la base de datos y por semana aquí, para que la
     * consulta sea igual en PostgreSQL y en SQLite.
     *
     * @param  list<int>  $userIds
     * @return array<int, array<string, int>>
     */
    private function actualMinutes(array $userIds, string $from, string $to): array
    {
        $rows = DB::table('time_entries')
            ->whereIn('user_id', $userIds)
            ->whereBetween('work_date', [$from, $to])
            ->where('status', '!=', TimeEntryStatus::Rejected->value)
            ->groupBy('user_id', 'work_date')
            ->selectRaw('user_id, work_date, SUM(minutes) AS minutes')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $week = BusinessCalendar::parseDate(substr((string) $row->work_date, 0, 10))->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            $result[(int) $row->user_id][$week] = ($result[(int) $row->user_id][$week] ?? 0) + (int) $row->minutes;
        }

        return $result;
    }

    private function activeOn(Allocation $allocation, CarbonImmutable $day): bool
    {
        $date = $day->toDateString();

        return $allocation->start_date->toDateString() <= $date
            && ($allocation->end_date === null || $allocation->end_date->toDateString() >= $date);
    }

    /**
     * Horas libres por especialidad y por sede: la semana en curso y el
     * promedio de todo el horizonte.
     *
     * @param  Collection<int, array<string, mixed>>  $people
     * @return array<string, list<array<string, mixed>>>
     */
    private function summary(Collection $people): array
    {
        $group = function (callable $key, callable $label) use ($people) {
            return $people->groupBy($key)->map(function (Collection $group, $value) use ($label) {
                $weeks = count($group->first()['weeks']);
                $freeAll = $group->sum(fn ($p) => array_sum(array_column($p['weeks'], 'free_minutes')));

                return [
                    'value' => $value,
                    'label' => $label($group->first()),
                    'people' => $group->count(),
                    'capacity_minutes_this_week' => $group->sum(fn ($p) => $p['weeks'][0]['capacity_minutes']),
                    'free_minutes_this_week' => $group->sum(fn ($p) => $p['weeks'][0]['free_minutes']),
                    'average_free_minutes' => $weeks > 0 ? (int) round($freeAll / $weeks) : 0,
                ];
            })->sortByDesc('free_minutes_this_week')->values()->all();
        };

        return [
            'by_specialty' => $group(fn ($p) => $p['specialty']['value'] ?? 'none', fn ($p) => $p['specialty']['label'] ?? 'Sin especialidad'),
            'by_site' => $group(fn ($p) => $p['site']['id'], fn ($p) => $p['site']['name']),
        ];
    }
}
