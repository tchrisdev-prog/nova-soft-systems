<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            // Indica si ya ingresó al menos una vez con Microsoft 365 (no expone el identificador).
            'microsoft_linked' => $this->microsoft_oid !== null,
            'is_super_admin' => $this->whenLoaded('roles', fn () => $this->roles->contains(
                fn (Role $role) => $role->isSuperAdmin() && $role->is_active
            )),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'is_active' => $role->is_active,
                'is_system' => $role->is_system,
            ])->values()),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
