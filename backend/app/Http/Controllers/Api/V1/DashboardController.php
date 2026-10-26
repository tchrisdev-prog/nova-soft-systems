<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ModuleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Indicadores reales calculados desde la base de datos. Cada bloque se
 * incluye solo si el usuario tiene permiso para consultar esa información.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = [
            'modules' => [
                'active' => Module::query()->where('status', ModuleStatus::Active->value)->count(),
                'planned' => Module::query()->where('status', ModuleStatus::Planned->value)->count(),
            ],
        ];

        if ($user->hasPermission('users.view')) {
            $data['users'] = [
                'total' => User::query()->count(),
                'active' => User::query()->where('is_active', true)->count(),
                'pending_first_login' => User::query()->whereNull('microsoft_oid')->count(),
            ];
        }

        if ($user->hasPermission('access_control.view')) {
            $data['roles'] = [
                'total' => Role::query()->count(),
                'active' => Role::query()->where('is_active', true)->count(),
            ];
        }

        if ($user->hasPermission('audit.view')) {
            $data['recent_activity'] = AuditLogResource::collection(
                AuditLog::query()->withPeople()->latest('created_at')->latest('id')->limit(6)->get()
            )->resolve($request);
        }

        return response()->json(['data' => $data]);
    }
}
