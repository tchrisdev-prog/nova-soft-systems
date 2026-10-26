<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\TimeEntries\StoreTimeEntryRequest;
use App\Http\Requests\TimeEntries\TimeEntryBatchRequest;
use App\Http\Requests\TimeEntries\TimeEntryIndexRequest;
use App\Http\Requests\TimeEntries\UpdateTimeEntryRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\TimeEntryResource;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\Timesheets\TimeEntryQuery;
use App\Services\Timesheets\TimeEntryService;
use App\Support\BusinessCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TimeEntryController extends Controller
{
    public function __construct(
        private readonly TimeEntryService $entries,
        private readonly TimeEntryQuery $query,
    ) {}

    public function index(TimeEntryIndexRequest $request): AnonymousResourceCollection
    {
        $entries = $this->query->for($request->user(), $request->scope(), $request->filters())
            ->with(['user', 'project', 'reviewer'])
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->paginate($request->validated('per_page') ?? 20)
            ->withQueryString();

        return TimeEntryResource::collection($entries);
    }

    /** Métricas del mismo conjunto que se está viendo (mismo alcance y filtros). */
    public function summary(TimeEntryIndexRequest $request): JsonResponse
    {
        $query = $this->query->for($request->user(), $request->scope(), $request->filters());
        $withCost = $request->user()->can('viewFinancials', TimeEntry::class);

        return response()->json(['data' => $this->query->summary($query, $withCost)]);
    }

    /** Proyectos en los que la persona puede registrar horas hoy. */
    public function projects(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('create', TimeEntry::class);
        $user = $request->user();
        $today = BusinessCalendar::today()->toDateString();

        $projects = Project::query()
            ->with(['contract.client'])
            ->where('status', ProjectStatus::Active->value)
            ->where(fn ($q) => $q
                ->where('leader_id', $user->getKey())
                ->orWhereHas('allocations', fn ($a) => $a->where('user_id', $user->getKey())->activeOn($today)))
            ->orderBy('code')
            ->get();

        return ProjectResource::collection($projects);
    }

    public function store(StoreTimeEntryRequest $request): JsonResponse
    {
        $entry = $this->entries->create($request->user(), $request->validated());

        return (new TimeEntryResource($entry->load(['user', 'project', 'reviewer'])))
            ->additional(['message' => 'Horas registradas en borrador.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateTimeEntryRequest $request, TimeEntry $timeEntry): TimeEntryResource
    {
        $entry = $this->entries->update($request->user(), $timeEntry, $request->validated());

        return (new TimeEntryResource($entry->load(['user', 'project', 'reviewer'])))->additional(['message' => 'Registro actualizado.']);
    }

    public function destroy(TimeEntry $timeEntry): JsonResponse
    {
        Gate::authorize('delete', $timeEntry);
        $this->entries->delete($timeEntry);

        return response()->json(['message' => 'Registro eliminado.']);
    }

    public function submit(TimeEntryBatchRequest $request): JsonResponse
    {
        $count = $this->entries->submit($request->user(), $request->ids());

        return response()->json(['message' => $count === 1 ? 'Registro enviado a aprobación.' : "{$count} registros enviados a aprobación.", 'data' => ['count' => $count]]);
    }

    public function approve(TimeEntryBatchRequest $request): JsonResponse
    {
        $count = $this->entries->approve($request->user(), $request->ids());

        return response()->json(['message' => $count === 1 ? 'Registro aprobado.' : "{$count} registros aprobados.", 'data' => ['count' => $count]]);
    }

    public function reject(TimeEntryBatchRequest $request): JsonResponse
    {
        $count = $this->entries->reject($request->user(), $request->ids(), (string) $request->validated('reason'));

        return response()->json(['message' => $count === 1 ? 'Registro rechazado.' : "{$count} registros rechazados.", 'data' => ['count' => $count]]);
    }
}
