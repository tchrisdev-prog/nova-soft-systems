<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\SyncUserRolesRequest;
use App\Http\Requests\Users\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Users\UserAdministrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function __construct(private readonly UserAdministrationService $users) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $users = User::query()
            ->with('roles')
            ->search($validated['search'] ?? null)
            ->when(($validated['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($validated['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($validated['role_id'] ?? null, fn ($q, $roleId) => $q->whereHas('roles', fn ($r) => $r->whereKey($roleId)))
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->users->authorize($request->user(), $request->validated());

        $message = $user->wasRecentlyCreated
            ? 'Cuenta autorizada. Podrá ingresar con su usuario de Microsoft 365.'
            : 'Esta cuenta se había eliminado y se recuperó con su historial. Podrá ingresar de nuevo con Microsoft 365.';

        return (new UserResource($user))
            ->additional(['message' => $message])
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user->load('roles'));
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): UserResource
    {
        $active = $request->boolean('is_active');
        $user = $this->users->changeStatus($request->user(), $user, $active);

        return (new UserResource($user))
            ->additional(['message' => $active ? 'Usuario activado.' : 'Usuario desactivado y sesiones cerradas.']);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        Gate::authorize('delete', $user);
        $this->users->delete($request->user(), $user);

        return response()->json(['message' => "Cuenta de {$user->name} eliminada. Su historial se conserva."]);
    }

    public function syncRoles(SyncUserRolesRequest $request, User $user): UserResource
    {
        $user = $this->users->syncRoles($request->user(), $user, $request->roleIds());

        return (new UserResource($user))->additional(['message' => 'Roles del usuario actualizados.']);
    }
}
