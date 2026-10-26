<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreAllocationRequest;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateAllocationRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Requests\Projects\UpdateProjectStatusRequest;
use App\Http\Resources\AllocationResource;
use App\Http\Resources\ProjectResource;
use App\Models\Allocation;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\AllocationService;
use App\Services\Projects\ProjectHealth;
use App\Services\Projects\ProjectReport;
use App\Services\Projects\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function __construct(
        private readonly AllocationService $allocations,
        private readonly ProjectService $projects,
        private readonly ProjectHealth $health,
        private readonly ProjectReport $report,
    ) {}

    /**
     * Con projects.view se listan todos; con projects.lead solo los que la
     * persona lidera. El filtro va en la consulta, no después.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Project::class);
        $user = $request->user();

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ProjectStatus::class)],
            'kind' => ['nullable', Rule::enum(ProjectKind::class)],
            'contract_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'site_id' => ['nullable', 'integer'],
            'leader_id' => ['nullable', 'integer'],
            'mine' => ['nullable', 'boolean'],
            'open' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $projects = Project::query()
            ->with(['contract.client', 'leader', 'site'])
            ->withCount('allocations')
            ->when(! $user->hasPermission('projects.view') || $request->boolean('mine'), fn ($q) => $q->ledBy($user))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('LOWER(code) LIKE ?', ['%'.mb_strtolower($search).'%'])))
            ->when($validated['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($request->boolean('open'), fn ($q) => $q->where('status', '!=', ProjectStatus::Closed->value))
            ->when($validated['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($validated['contract_id'] ?? null, fn ($q, $v) => $q->where('contract_id', $v))
            ->when($validated['client_id'] ?? null, fn ($q, $v) => $q->whereHas('contract', fn ($c) => $c->where('client_id', $v)))
            ->when($validated['site_id'] ?? null, fn ($q, $v) => $q->where('site_id', $v))
            ->when($validated['leader_id'] ?? null, fn ($q, $v) => $q->where('leader_id', $v))
            ->orderBy('code')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        $this->health->attach($projects->getCollection());

        return ProjectResource::collection($projects);
    }

    /**
     * Personas que pueden liderar un proyecto (para el selector de líder).
     * Solo lo consulta quien puede crear proyectos o cambiar su líder.
     */
    public function leaders(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('projects.create')
                || $request->user()->hasPermission('projects.update')
                || $request->user()->hasPermission('projects.manage'),
            403,
        );

        $leaders = User::query()
            ->active()
            ->withPermission('projects.lead')
            ->with('staffProfile')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'position' => $u->staffProfile?->position]);

        return response()->json(['data' => $leaders]);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = $this->projects->create($request->user(), $request->projectData());

        return $this->detail($request, $project)
            ->additional(['message' => "Proyecto {$project->code} creado."])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Project $project): ProjectResource
    {
        Gate::authorize('view', $project);

        return $this->detail($request, $project);
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $project->load('contract');
        $this->projects->update($request->user(), $project, $request->projectData());

        return $this->detail($request, $project)->additional(['message' => 'Proyecto actualizado.']);
    }

    public function updateStatus(UpdateProjectStatusRequest $request, Project $project): ProjectResource
    {
        $project->load('contract');
        $next = $request->status();
        $this->projects->changeStatus($request->user(), $project, $next, $request->validated('note'));

        return $this->detail($request, $project)->additional(['message' => "Proyecto {$next->label()}."]);
    }

    public function updateLeader(Request $request, Project $project): ProjectResource
    {
        Gate::authorize('changeLeader', $project);
        $validated = $request->validate(['leader_id' => ['required', 'integer']]);

        $this->projects->changeLeader($request->user(), $project, (int) $validated['leader_id']);

        return $this->detail($request, $project)->additional(['message' => 'Líder actualizado.']);
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('delete', $project);
        $this->projects->delete($request->user(), $project);

        return response()->json(['message' => "Proyecto {$project->code} eliminado."]);
    }

    /** Horas por consultor y semana (las últimas 4, 8 o 12). */
    public function hours(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);
        $validated = $request->validate(['weeks' => ['nullable', 'integer', Rule::in([4, 8, 12])]]);

        return response()->json(['data' => $this->report->hoursByWeek($project, (int) ($validated['weeks'] ?? 8))]);
    }

    public function allocations(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        return AllocationResource::collection(
            $project->allocations()->with('user.staffProfile')->orderByRaw('end_date IS NOT NULL')->orderBy('start_date')->get()
        );
    }

    public function storeAllocation(StoreAllocationRequest $request, Project $project): JsonResponse
    {
        $allocation = $this->allocations->assign(
            $request->user(),
            $project,
            $request->allocationData(),
            $request->boolean('confirm_overallocation'),
        );

        return (new AllocationResource($allocation->load('user.staffProfile')))
            ->additional(['message' => 'Consultor asignado al proyecto.'])
            ->response()
            ->setStatusCode(201);
    }

    public function updateAllocation(UpdateAllocationRequest $request, Project $project, Allocation $allocation): AllocationResource
    {
        $allocation->setRelation('project', $project)->load('user.staffProfile');
        $this->allocations->update($request->user(), $allocation, $request->allocationData(), $request->boolean('confirm_overallocation'));

        return (new AllocationResource($allocation))->additional(['message' => 'Asignación actualizada.']);
    }

    public function endAllocation(Request $request, Project $project, Allocation $allocation): AllocationResource
    {
        Gate::authorize('manageAllocations', $project);
        $validated = $request->validate(['end_date' => ['nullable', 'date_format:Y-m-d']]);

        $allocation->setRelation('project', $project)->load('user.staffProfile');
        $this->allocations->end($request->user(), $allocation, $validated['end_date'] ?? null);

        return (new AllocationResource($allocation))->additional(['message' => 'Asignación terminada.']);
    }

    public function destroyAllocation(Request $request, Project $project, Allocation $allocation): JsonResponse
    {
        Gate::authorize('manageAllocations', $project);
        $allocation->setRelation('project', $project);
        $this->allocations->remove($request->user(), $allocation);

        return response()->json(['message' => 'Asignación eliminada.']);
    }

    private function detail(Request $request, Project $project): ProjectResource
    {
        $project->load(['contract.client', 'leader', 'site'])->loadCount('allocations');
        $this->health->attach($project);

        if ($request->user()->can('viewFinancials', $project)) {
            $project->setRelation('financials', $this->report->financials($project));
        }

        return new ProjectResource($project);
    }
}
