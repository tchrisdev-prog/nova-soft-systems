<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsBusinessData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Client */
class ClientResource extends JsonResource
{
    use FormatsBusinessData;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'tax_id' => $this->tax_id,
            'sector' => $this->enumValue($this->sector),
            'site' => $this->whenLoaded('site', fn () => ['id' => $this->site->id, 'name' => $this->site->name]),
            'contacts' => ClientContactResource::collection($this->whenLoaded('contacts')),
            'primary_contact' => $this->whenLoaded('contacts', fn () => ($primary = $this->contacts->firstWhere('is_primary', true))
                ? (new ClientContactResource($primary))->resolve($request)
                : null),
            'is_active' => $this->is_active,
            // Resumen comercial (mensualidades, bolsas, alertas), calculado por ClientInsights.
            'summary' => $this->whenLoaded('summary'),
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
                'create_contract' => $request->user()?->can('create', \App\Models\Contract::class) ?? false,
            ],
            'contracts_count' => $this->whenCounted('contracts'),
            'active_contracts_count' => $this->whenCounted('active_contracts'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
