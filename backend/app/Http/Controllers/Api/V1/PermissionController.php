<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModuleResource;
use App\Models\Module;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PermissionController extends Controller
{
    /** Permisos agrupados por módulo, para la matriz de asignación. */
    public function index(): AnonymousResourceCollection
    {
        return ModuleResource::collection(
            Module::query()
                ->with(['permissions' => fn ($q) => $q->orderBy('id')])
                ->orderBy('sort_order')
                ->get()
        );
    }
}
