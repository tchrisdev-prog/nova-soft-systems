<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\StoreRoleRequest;
use App\Http\Requests\Roles\SyncRolePermissionsRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use App\Http\Requests\Roles\UpdateRoleStatusRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\Roles\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Role::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $roles = Role::query()
            ->withCount(['permissions', 'users'])
            ->search($validated['search'] ?? null)
            ->when(($validated['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($validated['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return RoleResource::collection($roles);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roles->create($request->user(), $request->validated());

        return (new RoleResource($role->loadCount(['permissions', 'users'])))
            ->additional(['message' => 'Rol creado correctamente.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Role $role): RoleResource
    {
        Gate::authorize('view', $role);

        return new RoleResource($role->load('permissions.module')->loadCount(['permissions', 'users']));
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $role = $this->roles->update($request->user(), $role, $request->validated());

        return (new RoleResource($role->loadCount(['permissions', 'users'])))
            ->additional(['message' => 'Rol actualizado correctamente.']);
    }

    public function updateStatus(UpdateRoleStatusRequest $request, Role $role): RoleResource
    {
        $active = $request->boolean('is_active');
        $role = $this->roles->changeStatus($request->user(), $role, $active);

        return (new RoleResource($role->loadCount(['permissions', 'users'])))
            ->additional(['message' => $active ? 'Rol activado.' : 'Rol desactivado. Sus usuarios dejarán de tener estos permisos.']);
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): RoleResource
    {
        $role = $this->roles->syncPermissions($request->user(), $role, $request->permissionIds());

        return (new RoleResource($role->loadCount(['permissions', 'users'])))
            ->additional(['message' => 'Permisos del rol actualizados.']);
    }
}
