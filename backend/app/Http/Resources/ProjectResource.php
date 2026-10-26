<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsBusinessData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Project */
class ProjectResource extends JsonResource
{
    use FormatsBusinessData;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'kind' => $this->enumValue($this->kind),
            'status' => $this->enumValue($this->status),
            'contract' => $this->whenLoaded('contract', fn () => [
                'id' => $this->contract->id,
                'code' => $this->contract->code,
                'name' => $this->contract->name,
                'client' => $this->contract->relationLoaded('client') ? ['id' => $this->contract->client->id, 'name' => $this->contract->client->name] : null,
            ]),
            'leader' => $this->whenLoaded('leader', fn () => $this->leader ? ['id' => $this->leader->id, 'name' => $this->leader->name] : null),
            'site' => $this->whenLoaded('site', fn () => ['id' => $this->site->id, 'name' => $this->site->name]),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'budget_minutes' => $this->budget_minutes,
            'approved_minutes' => $this->when(isset($this->approved_minutes), fn () => (int) $this->approved_minutes),
            'description' => $this->description,
            'allocations_count' => $this->whenCounted('allocations'),
            'health' => $this->whenLoaded('health'),
            // Solo con projects.financials; el controlador ni siquiera lo calcula para los demás.
            'financials' => $this->when($this->canSeeFinancials($request) && $this->relationLoaded('financials'), fn () => $this->getRelation('financials')),
            'next_statuses' => array_map(fn ($s) => $this->enumValue($s), $this->status->allowedTransitions()),
            'can' => $this->permissions($request),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Lo que la interfaz puede ofrecer. Es solo una guía para mostrar botones:
     * cada operación vuelve a autorizarse en el servidor.
     *
     * @return array<string, bool>
     */
    private function permissions(Request $request): array
    {
        $user = $request->user();

        if (! $user) {
            return [];
        }

        return [
            'update' => $user->can('update', $this->resource),
            'change_status' => $user->can('changeStatus', $this->resource),
            'change_leader' => $user->can('changeLeader', $this->resource),
            'manage_allocations' => $user->can('manageAllocations', $this->resource),
            'delete' => $user->can('delete', $this->resource),
            'view_financials' => $this->canSeeFinancials($request),
        ];
    }
}
