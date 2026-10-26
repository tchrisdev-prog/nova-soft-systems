<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsBusinessData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un registro de horas. El costo (tarifa interna × horas) solo se incluye
 * para quien tiene projects.financials; para los demás el campo no existe
 * en la respuesta, no basta con ocultarlo en la pantalla.
 *
 * @mixin \App\Models\TimeEntry
 */
class TimeEntryResource extends JsonResource
{
    use FormatsBusinessData;

    public function toArray(Request $request): array
    {
        $user = $request->user();
        $financials = $this->canSeeFinancials($request);

        return [
            'id' => $this->id,
            'work_date' => $this->work_date?->toDateString(),
            'minutes' => $this->minutes,
            'hours' => round($this->minutes / 60, 2),
            'description' => $this->description,
            'is_billable' => $this->is_billable,
            'is_after_hours' => $this->is_after_hours,
            'is_on_call' => $this->is_on_call,
            'status' => $this->enumValue($this->status),
            'user' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'name' => $this->user->name]),
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ]),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name] : null),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            $this->mergeWhen($financials, fn () => [
                'cost_rate' => $this->cost_rate,
                'cost' => $this->cost_rate !== null ? intdiv($this->minutes * $this->cost_rate, 60) : null,
            ]),
            'can' => $this->when($this->resource->relationLoaded('project'), fn () => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'delete' => $user?->can('delete', $this->resource) ?? false,
                'review' => ($user?->can('review', $this->resource) ?? false) && $this->status->value === 'submitted',
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
