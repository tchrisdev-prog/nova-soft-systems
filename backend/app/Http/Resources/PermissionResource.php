<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Permission */
class PermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'action' => $this->action,
            'action_label' => config("nova.actions.{$this->action}", ucfirst($this->action)),
            'description' => $this->description,
            'module_code' => $this->whenLoaded('module', fn () => $this->module->code),
        ];
    }
}
