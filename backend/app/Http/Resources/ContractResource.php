<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsBusinessData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El valor del contrato (mensualidad, valor de la bolsa) es información
 * comercial y lo ve quien consulta contratos. Lo que no aparece aquí es el
 * costo interno: ese vive en el consumo y solo con projects.financials.
 *
 * @mixin \App\Models\Contract
 */
class ContractResource extends JsonResource
{
    use FormatsBusinessData;

    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'code' => $this->client->code,
                'name' => $this->client->name,
            ]),
            'service_line' => $this->enumValue($this->service_line),
            'billing_model' => $this->enumValue($this->billing_model),
            'coverage' => $this->enumValue($this->coverage),
            'status' => $this->enumValue($this->status),
            // A qué estados puede pasar (la regla vive en ContractStatus).
            'next_statuses' => array_map(fn ($status) => $this->enumValue($status), $this->status->allowedTransitions()),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'monthly_fee' => $this->monthly_fee,
            'included_minutes_per_month' => $this->included_minutes_per_month,
            'hour_bag' => $this->when($this->isHourBag() && isset($this->purchased_minutes), fn () => [
                'purchased_minutes' => (int) $this->purchased_minutes,
                'contract_value' => (int) $this->contract_value,
            ]),
            'notes' => $this->notes,
            // Consumo y alertas calculados por ContractInsights. Los datos de
            // ingresos, costos y margen solo van a quien tiene projects.financials.
            'consumption' => $this->whenLoaded('insights', fn () => $this->insights['consumption']),
            'alerts' => $this->whenLoaded('insights', fn () => $this->insights['alerts']),
            'financials' => $this->when(
                $this->relationLoaded('insights') && $this->canSeeFinancials($request),
                fn () => $this->insights['financials'],
            ),
            'projects_count' => $this->whenCounted('projects'),
            'can' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'change_status' => $user?->can('changeStatus', $this->resource) ?? false,
                'add_hours' => $this->isHourBag() && ($user?->can('addHours', $this->resource) ?? false),
                // Solo se borran borradores sin proyectos; los demás se cancelan.
                'delete' => $this->status->value === 'draft' && (int) ($this->projects_count ?? 1) === 0 && ($user?->can('delete', $this->resource) ?? false),
                'view_financials' => $this->canSeeFinancials($request),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
