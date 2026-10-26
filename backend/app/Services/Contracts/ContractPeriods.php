<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Enums\BillingModel;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\ContractPeriod;
use App\Support\BusinessCalendar;
use Carbon\CarbonImmutable;

/**
 * Abre los meses de las mensualidades. Cada periodo toma una "foto" de las
 * condiciones vigentes (valor y horas incluidas) el día que se abre; si el
 * contrato cambia después, el cambio aplica desde el mes siguiente y los
 * meses anteriores quedan como se pactaron.
 */
class ContractPeriods
{
    public function __construct(private readonly ContractHistory $history) {}

    /**
     * Garantiza el periodo del mes que contiene $day. Devuelve null si el
     * contrato no es una mensualidad activa o si ese mes está fuera de sus fechas.
     */
    public function ensureFor(Contract $contract, CarbonImmutable $day): ?ContractPeriod
    {
        if ($contract->billing_model !== BillingModel::MonthlyRecurring || $contract->status !== ContractStatus::Active) {
            return null;
        }

        $start = $day->startOfMonth();
        $end = $day->endOfMonth()->startOfDay();

        $contractStart = CarbonImmutable::parse($contract->start_date->toDateString(), BusinessCalendar::timezone());
        $contractEnd = $contract->end_date ? CarbonImmutable::parse($contract->end_date->toDateString(), BusinessCalendar::timezone()) : null;

        if ($end->lt($contractStart) || ($contractEnd && $start->gt($contractEnd))) {
            return null;
        }

        $existing = $contract->periods()->whereDate('period_start', $start->toDateString())->first();

        if ($existing) {
            return $existing;
        }

        $period = new ContractPeriod;
        $period->forceFill([
            'contract_id' => $contract->getKey(),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'fee_amount' => (int) $contract->monthly_fee,
            'included_minutes' => $contract->included_minutes_per_month,
        ])->save();

        $this->history->record($contract, null, 'period_opened', [
            'period' => ['old' => null, 'new' => $start->format('Y-m')],
            'fee_amount' => ['old' => null, 'new' => $period->fee_amount],
            'included_minutes' => ['old' => null, 'new' => $period->included_minutes],
        ]);

        return $period;
    }

    /** Abre el mes en curso de todas las mensualidades activas. Es idempotente. */
    public function renewAll(?CarbonImmutable $today = null): int
    {
        $today ??= BusinessCalendar::today();
        $opened = 0;

        Contract::query()
            ->where('billing_model', BillingModel::MonthlyRecurring->value)
            ->where('status', ContractStatus::Active->value)
            ->orderBy('id')
            ->each(function (Contract $contract) use ($today, &$opened): void {
                $before = $contract->periods()->count();
                $this->ensureFor($contract, $today);
                $opened += $contract->periods()->count() - $before;
            });

        return $opened;
    }
}
