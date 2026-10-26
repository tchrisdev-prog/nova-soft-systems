<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ModuleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Module;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Perfil del usuario autenticado, permisos efectivos y módulos visibles.
 * El frontend usa esta información solo para adaptar la interfaz; cada
 * operación se vuelve a autorizar en el servidor.
 */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles');
        $permissions = $user->permissionCodes();

        $modules = Module::query()
            ->where('status', '!=', ModuleStatus::Inactive->value)
            ->orderBy('sort_order')
            ->get()
            ->map(function (Module $module) use ($permissions) {
                $actions = $permissions
                    ->filter(fn (string $code) => str_starts_with($code, $module->code.'.'))
                    ->map(fn (string $code) => substr($code, strlen($module->code) + 1))
                    ->values();

                return [
                    'code' => $module->code,
                    'name' => $module->name,
                    'description' => $module->description,
                    'icon' => $module->icon,
                    'status' => $module->status->value,
                    'status_label' => $module->status->label(),
                    'can_access' => $module->status === ModuleStatus::Active && $actions->isNotEmpty(),
                    'actions' => $actions,
                ];
            });

        return response()->json(['data' => [
            'user' => new UserResource($user),
            'is_super_admin' => $user->isSuperAdmin(),
            'permissions' => $permissions,
            'modules' => $modules,
        ]]);
    }
}
