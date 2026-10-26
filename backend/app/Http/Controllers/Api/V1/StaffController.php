<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\StaffSpecialty;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\UpdateStaffProfileRequest;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Projects\StaffService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Perfiles laborales. Se listan todas las personas activas, tengan perfil o
 * no, para que se vea quién falta por configurar.
 */
class StaffController extends Controller
{
    public function __construct(private readonly StaffService $staff) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', StaffProfile::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'site_id' => ['nullable', 'integer'],
            'specialty' => ['nullable', Rule::enum(StaffSpecialty::class)],
            'missing' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $showCost = $request->user()->can('manageCost', StaffProfile::class);

        $page = User::query()
            ->where('is_active', true)
            ->with('staffProfile.site')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('LOWER(email) LIKE ?', ['%'.mb_strtolower($search).'%'])))
            ->when($validated['site_id'] ?? null, fn (Builder $q, $v) => $q->whereHas('staffProfile', fn (Builder $p) => $p->where('site_id', $v)))
            ->when($validated['specialty'] ?? null, fn (Builder $q, $v) => $q->whereHas('staffProfile', fn (Builder $p) => $p->where('specialty', $v)))
            ->when($request->boolean('missing'), fn (Builder $q) => $q->whereDoesntHave('staffProfile'))
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        $page->getCollection()->transform(fn (User $user) => $this->present($user, $showCost));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'missing_profiles' => User::query()->where('is_active', true)->whereDoesntHave('staffProfile')->count(),
            ],
            'can' => ['manage_cost' => $showCost],
        ]);
    }

    public function update(UpdateStaffProfileRequest $request, User $user): JsonResponse
    {
        $this->staff->save($request->user(), $user, $request->validated());

        return response()->json([
            'data' => $this->present($user->load('staffProfile.site'), $request->user()->can('manageCost', StaffProfile::class)),
            'message' => 'Perfil guardado.',
        ]);
    }

    /** @return array<string, mixed> */
    private function present(User $user, bool $showCost): array
    {
        $profile = $user->staffProfile;
        $data = null;

        if ($profile) {
            $data = [
                'site' => ['id' => $profile->site->id, 'name' => $profile->site->name],
                'position' => $profile->position,
                'specialty' => $profile->specialty ? ['value' => $profile->specialty->value, 'label' => $profile->specialty->label()] : null,
                'weekly_hours' => round($profile->weekly_capacity_minutes / 60, 2),
            ];

            // Sin projects.financials la llave ni siquiera aparece.
            if ($showCost) {
                $data['hourly_cost'] = $profile->hourly_cost;
            }
        }

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'profile' => $data,
        ];
    }
}
