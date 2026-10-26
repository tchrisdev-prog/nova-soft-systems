<?php

namespace App\Http\Resources;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Role */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'is_system' => $this->is_system,
            'permissions_count' => $this->whenCounted('permissions'),
            'users_count' => $this->whenCounted('users'),
            'permission_ids' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('id')->sort()->values()),
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
            'module_codes' => $this->whenLoaded('permissions', fn () => $this->permissions
                ->map(fn (Permission $p) => $p->relationLoaded('module') ? $p->module?->code : strtok($p->code, '.'))
                ->filter()->unique()->sort()->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
