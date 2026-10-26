<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\BusinessCalendar;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Allocation */
class AllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $today = BusinessCalendar::today()->toDateString();
        $start = $this->start_date?->toDateString();
        $end = $this->end_date?->toDateString();

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'position' => $this->user->relationLoaded('staffProfile') ? $this->user->staffProfile?->position : null,
            ]),
            'role_label' => $this->role_label,
            'allocation_percent' => $this->allocation_percent,
            'start_date' => $start,
            'end_date' => $end,
            // upcoming: aún no empieza · current: vigente hoy · ended: ya terminó
            'state' => $start > $today ? 'upcoming' : (($end !== null && $end < $today) ? 'ended' : 'current'),
        ];
    }
}
