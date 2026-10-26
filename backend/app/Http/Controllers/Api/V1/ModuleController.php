<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModuleResource;
use App\Models\Module;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ModuleController extends Controller
{
    /** Catálogo de módulos (activos y planificados) para cualquier usuario autenticado. */
    public function index(): AnonymousResourceCollection
    {
        return ModuleResource::collection(Module::query()->orderBy('sort_order')->get());
    }
}
