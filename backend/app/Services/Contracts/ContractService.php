<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Enums\BillingModel;
use App\Enums\ContractStatus;
use App\Enums\HourMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractHourMovement;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Support\CodeGenerator;
use App\Support\BusinessCalendar;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Todo cambio a un contrato pasa por aquí, y todo cambio deja una línea en
 * su historial. No hay otra forma de modificar un contrato desde la API.
 */
class ContractService
{
    public function __construct(
        private readonly ContractHistory $history,
        private readonly ContractPeriods $periods,
        private readonly AuditLogger $audit,
        private readonly CodeGenerator $codes,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Datos validados por StoreContractRequest.
     */
    public function create(User $actor, Client $client, array $data): Contract
    {
        if (! $client->is_active) {
            throw new BusinessRuleException('El cliente está inactivo. Actívalo antes de registrarle contratos.', 'inactive_client', 422);
        }

        return DB::transaction(function () use ($actor, $client, $data) {
            $billing = BillingModel::from($data['billing_model']);

            $contract = new Contract(Arr::only($data, (new Contract)->getFillable()));
            $contract->forceFill([
                'client_id' => $client->getKey(),
                // CT-2026-001: el año es el de inicio del contrato.
                'code' => $data['code'] ?? $this->codes->nextContractCode((int) substr((string) $data['start_date'], 0, 4)),
                'billing_model' => $billing,
                'status' => ContractStatus::Draft,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            // Una bolsa de horas no tiene mensualidad, y viceversa.
            if ($billing === BillingModel::HourBag) {
                $contract->forceFill(['monthly_fee' => null, 'included_minutes_per_month' => null]);
            }

            $contract->save();

            $this->history->record($contract, $actor, 'created', [
                'code' => ['old' => null, 'new' => $contract->code],
                'billing_model' => ['old' => null, 'new' => $billing->value],
                'service_line' => ['old' => null, 'new' => $contract->service_line->value],
            ]);

            if ($billing === BillingModel::HourBag) {
                $this->addMovement($actor, $contract, HourMovementType::Initial, (int) $data['initial_minutes'], (int) ($data['initial_amount'] ?? 0), $contract->start_date->toDateString(), 'Bolsa inicial del contrato.');
            }

            $this->audit->log('contract.created', $actor, $contract, ['code' => $contract->code, 'client' => $client->code]);

            return $contract;
        });
    }

    /**
     * El cliente y el tipo de cobro no se cambian: si cambian, en realidad es
     * otro contrato. Los cambios de valor en una mensualidad aplican desde el
     * próximo mes (el mes en curso ya se abrió con sus condiciones).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Contract $contract, array $data, ?string $note = null): Contract
    {
        $this->assertOpen($contract);

        return DB::transaction(function () use ($actor, $contract, $data, $note) {
            $contract->fill(Arr::only($data, $contract->getFillable()));

            if ($contract->isHourBag()) {
                $contract->forceFill(['monthly_fee' => null, 'included_minutes_per_month' => null]);
            }

            $changes = ContractHistory::diff($contract);

            if ($changes === []) {
                return $contract;
            }

            $contract->forceFill(['updated_by' => $actor->getKey()])->save();
            $this->history->record($contract, $actor, 'updated', $changes, $note);
            $this->audit->log('contract.updated', $actor, $contract, ['fields' => array_keys($changes)]);

            return $contract;
        });
    }

    public function changeStatus(User $actor, Contract $contract, ContractStatus $next, ?string $note = null): Contract
    {
        $current = $contract->status;

        if ($current === $next) {
            return $contract;
        }

        if (! $current->canTransitionTo($next)) {
            throw new BusinessRuleException(
                "Un contrato {$current->label()} no puede pasar a {$next->label()}.",
                'invalid_transition',
                409,
            );
        }

        return DB::transaction(function () use ($actor, $contract, $current, $next, $note) {
            $contract->forceFill(['status' => $next, 'updated_by' => $actor->getKey()])->save();

            $this->history->record($contract, $actor, 'status_changed', [
                'status' => ['old' => $current->value, 'new' => $next->value],
            ], $note);

            // Al activar una mensualidad, el mes en curso queda abierto de una vez.
            if ($next === ContractStatus::Active) {
                $this->periods->ensureFor($contract, BusinessCalendar::today());
            }

            $this->audit->log('contract.status_changed', $actor, $contract, ['from' => $current->value, 'to' => $next->value]);

            return $contract;
        });
    }

    /**
     * Recarga o ajuste de una bolsa de horas. Nunca se edita la bolsa: cada
     * movimiento es una línea nueva con quién la registró y por qué.
     */
    public function addHours(User $actor, Contract $contract, HourMovementType $type, int $minutes, int $amount, string $referenceDate, ?string $note): ContractHourMovement
    {
        if (! $contract->isHourBag()) {
            throw new BusinessRuleException('Solo los contratos de bolsa de horas admiten recargas.', 'not_an_hour_bag', 422);
        }

        if ($type === HourMovementType::Initial) {
            throw new BusinessRuleException('La bolsa inicial se registra al crear el contrato.', 'invalid_movement', 422);
        }

        $this->assertOpen($contract);

        return DB::transaction(function () use ($actor, $contract, $type, $minutes, $amount, $referenceDate, $note) {
            // Bloquea el contrato para que dos recargas simultáneas no calculen
            // el saldo sobre el mismo valor.
            Contract::query()->whereKey($contract->getKey())->lockForUpdate()->first();

            $purchased = (int) $contract->hourMovements()->sum('minutes');

            if ($purchased + $minutes < 0) {
                throw new BusinessRuleException('El ajuste dejaría la bolsa con horas negativas.', 'negative_hour_bag', 422);
            }

            return $this->addMovement($actor, $contract, $type, $minutes, $amount, $referenceDate, $note);
        });
    }

    public function delete(User $actor, Contract $contract): void
    {
        if ($contract->status !== ContractStatus::Draft || $contract->projects()->exists()) {
            throw new BusinessRuleException(
                'Solo se eliminan contratos en borrador y sin proyectos. Para los demás, cambia su estado a cancelado y así se conserva su historial.',
                'contract_in_use',
                409,
            );
        }

        DB::transaction(function () use ($actor, $contract) {
            $this->audit->log('contract.deleted', $actor, null, ['code' => $contract->code, 'contract_id' => $contract->getKey()]);
            $contract->hourMovements()->toBase()->delete();
            $contract->revisions()->toBase()->delete();
            $contract->delete();
        });
    }

    private function addMovement(User $actor, Contract $contract, HourMovementType $type, int $minutes, int $amount, string $referenceDate, ?string $note): ContractHourMovement
    {
        $movement = new ContractHourMovement;
        $movement->forceFill([
            'contract_id' => $contract->getKey(),
            'type' => $type,
            'minutes' => $minutes,
            'amount' => $amount,
            'reference_date' => $referenceDate,
            'note' => $note,
            'created_by' => $actor->getKey(),
            'created_at' => now(),
        ])->save();

        $event = match ($type) {
            HourMovementType::Initial => 'hours_initial',
            HourMovementType::TopUp => 'hours_added',
            HourMovementType::Adjustment => 'hours_adjusted',
        };

        $this->history->record($contract, $actor, $event, [
            'minutes' => ['old' => null, 'new' => $minutes],
            'amount' => ['old' => null, 'new' => $amount],
        ], $note);

        if ($type !== HourMovementType::Initial) {
            $this->audit->log('contract.'.$event, $actor, $contract, ['minutes' => $minutes, 'amount' => $amount]);
        }

        return $movement;
    }

    private function assertOpen(Contract $contract): void
    {
        if (in_array($contract->status, [ContractStatus::Finished, ContractStatus::Cancelled], true)) {
            throw new BusinessRuleException('El contrato está cerrado y ya no admite cambios.', 'contract_closed', 409);
        }
    }
}
