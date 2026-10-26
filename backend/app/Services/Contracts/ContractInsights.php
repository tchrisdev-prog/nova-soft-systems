<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Enums\BillingModel;
use App\Enums\ContractStatus;
use App\Enums\TimeEntryStatus;
use App\Models\Contract;
use App\Models\ContractPeriod;
use App\Support\BusinessCalendar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consumo resumido y alertas de varios contratos a la vez, para las listas.
 *
 * Todo se calcula con unas pocas consultas agrupadas para la página completa
 * (no una consulta por contrato), y nada se guarda: las alertas siempre
 * reflejan las horas aprobadas en este momento.
 *
 * Alertas:
 * - Bolsa por agotarse (desde el 80 %) y bolsa excedida.
 * - Mensualidad que ya superó sus horas incluidas este mes.
 * - Contrato activo que vence pronto o cuya fecha de fin ya pasó.
 */
class ContractInsights
{
    /**
     * @param  Collection<int, Contract>  $contracts
     * @return array<int, array{consumption: array<string, mixed>, financials: array<string, int>, alerts: list<array{code: string, level: string, message: string}>}>
     */
    public function for(Collection $contracts): array
    {
        if ($contracts->isEmpty()) {
            return [];
        }

        $ids = $contracts->map(fn ($contract) => $contract->getKey())->all();
        $today = BusinessCalendar::today();
        $monthStart = $today->startOfMonth()->toDateString();
        $monthEnd = $today->endOfMonth()->toDateString();

        $totals = $this->minutesByContract($ids);
        $thisMonth = $this->minutesByContract($ids, $monthStart, $monthEnd);
        $bags = DB::table('contract_hour_movements')
            ->whereIn('contract_id', $ids)
            ->groupBy('contract_id')
            ->selectRaw('contract_id, SUM(minutes) AS minutes, SUM(amount) AS amount')
            ->get()->keyBy('contract_id');
        $periods = ContractPeriod::query()->whereIn('contract_id', $ids)->whereDate('period_start', $monthStart)->get()->keyBy('contract_id');
        $billed = DB::table('contract_periods')->whereIn('contract_id', $ids)->whereDate('period_start', '<=', $monthStart)
            ->groupBy('contract_id')->selectRaw('contract_id, SUM(fee_amount) AS amount')->pluck('amount', 'contract_id');

        $result = [];

        foreach ($contracts as $contract) {
            $id = $contract->getKey();
            $total = $totals[$id] ?? ['approved' => 0, 'pending' => 0, 'cost' => 0];
            $month = $thisMonth[$id] ?? ['approved' => 0, 'pending' => 0, 'cost' => 0];

            if ($contract->billing_model === BillingModel::HourBag) {
                $purchased = (int) ($bags[$id]->minutes ?? 0);
                $revenue = (int) ($bags[$id]->amount ?? 0);
                $consumption = [
                    'type' => 'hour_bag',
                    'purchased_minutes' => $purchased,
                    'approved_minutes' => $total['approved'],
                    'pending_minutes' => $total['pending'],
                    'balance_minutes' => $purchased - $total['approved'],
                    'consumed_ratio' => $purchased > 0 ? round($total['approved'] / $purchased, 4) : null,
                ];
            } else {
                $period = $periods[$id] ?? null;
                $included = $period?->included_minutes ?? $contract->included_minutes_per_month;
                $revenue = (int) ($billed[$id] ?? 0);
                $consumption = [
                    'type' => 'monthly_recurring',
                    'period' => $today->format('Y-m'),
                    'fee_amount' => $period?->fee_amount ?? (int) $contract->monthly_fee,
                    'included_minutes' => $included,
                    'approved_minutes' => $month['approved'],
                    'pending_minutes' => $month['pending'],
                    'consumed_ratio' => $included ? round($month['approved'] / $included, 4) : null,
                ];
            }

            $result[$id] = [
                'consumption' => $consumption,
                // Ingreso pactado hasta hoy y costo interno de las horas aprobadas.
                // Solo se envía a quien tiene projects.financials.
                'financials' => [
                    'revenue_to_date' => $revenue,
                    'approved_cost' => $total['cost'],
                    'margin' => $revenue - $total['cost'],
                ],
                'alerts' => $this->alerts($contract, $consumption, $today),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $consumption
     * @return list<array{code: string, level: string, message: string}>
     */
    private function alerts(Contract $contract, array $consumption, $today): array
    {
        if (! in_array($contract->status, [ContractStatus::Active, ContractStatus::Suspended], true)) {
            return [];
        }

        $alerts = [];
        $ratio = $consumption['consumed_ratio'];
        $threshold = (float) config('nova.clients.bag_alert_threshold', 0.8);

        if ($consumption['type'] === 'hour_bag' && $ratio !== null) {
            if ($ratio > 1) {
                $alerts[] = $this->alert('bag_exceeded', 'danger', sprintf('Bolsa excedida en %s h', self::hours(-$consumption['balance_minutes'])));
            } elseif ($ratio >= $threshold) {
                $alerts[] = $this->alert('bag_low', 'warning', sprintf('Bolsa al %d %%: quedan %s h', (int) floor($ratio * 100), self::hours($consumption['balance_minutes'])));
            }
        }

        if ($consumption['type'] === 'monthly_recurring' && $consumption['included_minutes'] !== null && $consumption['approved_minutes'] > $consumption['included_minutes']) {
            $alerts[] = $this->alert('monthly_overage', 'warning', sprintf('Excedente este mes: %s h', self::hours($consumption['approved_minutes'] - $consumption['included_minutes'])));
        }

        if ($contract->status === ContractStatus::Active && $contract->end_date) {
            $end = $contract->end_date->toDateString();
            $days = (int) $today->diffInDays(BusinessCalendar::parseDate($end), false);

            if ($days < 0) {
                $alerts[] = $this->alert('expired', 'danger', 'La fecha de fin ya pasó: finalízalo o renueva sus fechas');
            } elseif ($days <= (int) config('nova.clients.expiring_days', 30)) {
                $alerts[] = $this->alert('expiring', 'warning', $days === 0 ? 'Vence hoy' : "Vence en {$days} ".($days === 1 ? 'día' : 'días'));
            }
        }

        return $alerts;
    }

    /**
     * Minutos aprobados y enviados por contrato (y costo de lo aprobado),
     * en una sola consulta agrupada.
     *
     * @param  list<int>  $ids
     * @return array<int, array{approved: int, pending: int, cost: int}>
     */
    private function minutesByContract(array $ids, ?string $from = null, ?string $to = null): array
    {
        $approved = TimeEntryStatus::Approved->value;
        $submitted = TimeEntryStatus::Submitted->value;

        return DB::table('time_entries')
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->whereIn('projects.contract_id', $ids)
            ->when($from, fn ($q) => $q->whereBetween('time_entries.work_date', [$from, $to]))
            ->groupBy('projects.contract_id')
            ->selectRaw(
                'projects.contract_id AS contract_id, '.
                'COALESCE(SUM(CASE WHEN time_entries.status = ? THEN time_entries.minutes ELSE 0 END), 0) AS approved, '.
                'COALESCE(SUM(CASE WHEN time_entries.status = ? THEN time_entries.minutes ELSE 0 END), 0) AS pending, '.
                'COALESCE(SUM(CASE WHEN time_entries.status = ? THEN time_entries.minutes * COALESCE(time_entries.cost_rate, 0) ELSE 0 END), 0) AS cost_minutes',
                [$approved, $submitted, $approved],
            )
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->contract_id => [
                'approved' => (int) $row->approved,
                'pending' => (int) $row->pending,
                'cost' => intdiv((int) $row->cost_minutes, 60),
            ]])
            ->all();
    }

    /** @return array{code: string, level: string, message: string} */
    private function alert(string $code, string $level, string $message): array
    {
        return ['code' => $code, 'level' => $level, 'message' => $message];
    }

    private static function hours(int $minutes): string
    {
        return number_format($minutes / 60, $minutes % 60 === 0 ? 0 : 1, ',', '.');
    }
}
