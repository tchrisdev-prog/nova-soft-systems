<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ContractRevision */
class ContractRevisionResource extends JsonResource
{
    private const EVENTS = [
        'created' => 'Contrato creado',
        'updated' => 'Datos actualizados',
        'status_changed' => 'Cambio de estado',
        'hours_initial' => 'Bolsa inicial',
        'hours_added' => 'Recarga de horas',
        'hours_adjusted' => 'Ajuste de horas',
        'period_opened' => 'Mes abierto',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'event_label' => self::EVENTS[$this->event] ?? $this->event,
            'changes' => $this->changes ?? [],
            'note' => $this->note,
            // Los cambios automáticos (por ejemplo, abrir el mes) no tienen autor.
            'actor' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
