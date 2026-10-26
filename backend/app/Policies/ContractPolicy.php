<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;

/**
 * Comercial y Gerencia trabajan los contratos. El valor del contrato es
 * información comercial y lo ve quien consulta contratos; lo que cuesta
 * ejecutarlo (costo interno y margen) solo lo ve quien tiene projects.financials.
 */
class ContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('clients.view');
    }

    public function view(User $user, Contract $contract): bool
    {
        return $user->hasPermission('clients.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('clients.create');
    }

    public function update(User $user, Contract $contract): bool
    {
        return $user->hasPermission('clients.update');
    }

    public function changeStatus(User $user, Contract $contract): bool
    {
        return $user->hasPermission('clients.manage');
    }

    public function addHours(User $user, Contract $contract): bool
    {
        return $user->hasPermission('clients.manage');
    }

    public function delete(User $user, Contract $contract): bool
    {
        return $user->hasPermission('clients.delete');
    }

    public function viewFinancials(User $user, ?Contract $contract = null): bool
    {
        return $user->hasPermission('projects.financials');
    }
}
