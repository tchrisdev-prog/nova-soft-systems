<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsBusinessData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ClientContact */
class ClientContactResource extends JsonResource
{
    use FormatsBusinessData;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->enumValue($this->type),
            'name' => $this->name,
            'position' => $this->position,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_primary' => $this->is_primary,
        ];
    }
}
