<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Enums\TimeEntryStatus;
use App\Models\Contract;
use App\Models\ContractPeriod;
use App\Models\TimeEntry;
use Illuminate\Support\Collection;

/**
 * Cuánto se ha consumido de un contrato.
 *
 * No se guarda un "saldo" en ninguna columna: se calcula cada vez a partir de
 * los movimientos de la bolsa y de las horas aprobadas. Así el saldo nunca
 * queda desincronizado. Con los índices de time_entries son consultas de suma
 * que responden en milisegundos para el volumen de la empresa.
 *
 * Solo cuentan como consumo las horas aprobadas; las enviadas se muestran
 * aparte como "pendientes" para que el líder vea lo que viene en camino.
 */
class ContractConsumption
{
    /**
     * @return array<string, mixed>
     */
    public function summarize(Contract $contract, int $months = 6): array
    {
        return $contract->isHourBag()
            ? $this->hourBag($contract)
            : $this->monthly($contract, $months);
    }

    /** @return array<string, mixed> */
    private function hourBag(Contract $contract): array
    {
        $movements = $contract->hourMovements()
            ->selectRaw('COALESCE(SUM(minutes), 0) AS minutes, COALESCE(SUM(amount), 0) AS amount')
            ->toBase()
            ->first();

        $totals = $this->totalsByStatus($contract);
        $purchased = (int) $movements->minutes;
        $approved = $totals['approved_minutes'];

        return [
            'type' => 'hour_bag',
            'purchased_minutes' => $purchased,
            'approved_minutes' => $approved,
            'pending_minutes' => $totals['pending_minutes'],
            'balance_minutes' => $purchased - $approved,
            'consumed_ratio' => $purchased > 0 ? round($approved / $purchased, 4) : null,
            'is_exceeded' => $approved > $purchased,
            'after_hours_minutes' => $totals['after_hours_minutes'],
            'on_call_minutes' => $totals['on_call_minutes'],
            'contract_value' => (int) $movements->amount,
            'cost' => $totals['cost'],
            'margin' => (int) $movements->amount - $totals['cost'],
        ];
    }

    /** @return array<string, mixed> */
    private function monthly(Contract $contract, int $months): array
    {
        /** @var Collection<int, ContractPeriod> $periods */
        $periods = $contract->periods()->orderByDesc('period_start')->limit($months)->get()->sortBy('period_start')->values();

        if ($periods->isEmpty()) {
            return ['type' => 'monthly_recurring', 'periods' => []];
        }

        // Una sola consulta: totales por día y estado para todo el rango, que
        // luego se reparten en cada mes. Evita una consulta por periodo y es
        // igual en PostgreSQL y en SQLite (no depende de funciones de fecha).
        $daily = $this->baseQuery($contract)
            ->whereBetween('work_date', [$periods->first()->period_start->toDateString(), $periods->last()->period_end->toDateString()])
            ->whereIn('status', [TimeEntryStatus::Approved->value, TimeEntryStatus::Submitted->value])
            ->groupBy('work_date', 'status')
            ->selectRaw('work_date, status, SUM(minutes) AS minutes, SUM(minutes * COALESCE(cost_rate, 0)) AS cost_minutes')
            ->toBase()
            ->get();

        $rows = $periods->map(function (ContractPeriod $period) use ($daily) {
            $from = $period->period_start->toDateString();
            $to = $period->period_end->toDateString();
            $inPeriod = $daily->filter(fn ($row) => substr((string) $row->work_date, 0, 10) >= $from && substr((string) $row->work_date, 0, 10) <= $to);

            $approved = (int) $inPeriod->where('status', TimeEntryStatus::Approved->value)->sum('minutes');
            $pending = (int) $inPeriod->where('status', TimeEntryStatus::Submitted->value)->sum('minutes');
            $cost = intdiv((int) $inPeriod->where('status', TimeEntryStatus::Approved->value)->sum('cost_minutes'), 60);
            $included = $period->included_minutes;

            return [
                'period' => $period->period_start->format('Y-m'),
                'period_start' => $from,
                'period_end' => $to,
                'fee_amount' => $period->fee_amount,
                // Sin horas incluidas el servicio se cobra por disponibilidad:
                // las horas igual se registran y se miden como costo interno.
                'included_minutes' => $included,
                'approved_minutes' => $approved,
                'pending_minutes' => $pending,
                'overage_minutes' => $included !== null ? max(0, $approved - $included) : null,
                'consumed_ratio' => $included ? round($approved / $included, 4) : null,
                'cost' => $cost,
                'margin' => $period->fee_amount - $cost,
            ];
        });

        $totals = $this->totalsByStatus($contract);

        return [
            'type' => 'monthly_recurring',
            'after_hours_minutes' => $totals['after_hours_minutes'],
            'on_call_minutes' => $totals['on_call_minutes'],
            'periods' => $rows->all(),
        ];
    }

    /**
     * @return array{approved_minutes: int, pending_minutes: int, after_hours_minutes: int, on_call_minutes: int, cost: int}
     */
    private function totalsByStatus(Contract $contract): array
    {
        $approved = TimeEntryStatus::Approved->value;
        $submitted = TimeEntryStatus::Submitted->value;

        $row = $this->baseQuery($contract)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? THEN minutes ELSE 0 END), 0) AS approved_minutes, '.
                'COALESCE(SUM(CASE WHEN status = ? THEN minutes ELSE 0 END), 0) AS pending_minutes, '.
                'COALESCE(SUM(CASE WHEN status = ? AND is_after_hours THEN minutes ELSE 0 END), 0) AS after_hours_minutes, '.
                'COALESCE(SUM(CASE WHEN status = ? AND is_on_call THEN minutes ELSE 0 END), 0) AS on_call_minutes, '.
                'COALESCE(SUM(CASE WHEN status = ? THEN minutes * COALESCE(cost_rate, 0) ELSE 0 END), 0) AS cost_minutes',
                [$approved, $submitted, $approved, $approved, $approved],
            )
            ->toBase()
            ->first();

        // Las columnas booleanas se evalúan directamente (sin comparar con 1 o
        // true) para que la misma consulta funcione en PostgreSQL y en SQLite.
        return [
            'approved_minutes' => (int) $row->approved_minutes,
            'pending_minutes' => (int) $row->pending_minutes,
            'after_hours_minutes' => (int) $row->after_hours_minutes,
            'on_call_minutes' => (int) $row->on_call_minutes,
            // cost_rate es por hora y las horas están en minutos: se divide al final
            // para no acumular errores de redondeo registro por registro.
            'cost' => intdiv((int) $row->cost_minutes, 60),
        ];
    }

    private function baseQuery(Contract $contract)
    {
        return TimeEntry::query()->whereIn('project_id', $contract->projects()->select('id'));
    }
}
