<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Module */
class ModuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'sort_order' => $this->sort_order,
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
        ];
    }
}
