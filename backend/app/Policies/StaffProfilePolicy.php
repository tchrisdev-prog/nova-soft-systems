<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StaffProfile;
use App\Models\User;

/**
 * Perfiles laborales y capacidad.
 * - projects.capacity: ver la disponibilidad (Comercial, líderes, gerencia).
 * - projects.staff: editar sede, cargo, especialidad y jornada.
 * - projects.financials: además, ver y editar el costo por hora.
 */
class StaffProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('projects.staff');
    }

    public function update(User $user, ?StaffProfile $profile = null): bool
    {
        return $user->hasPermission('projects.staff');
    }

    public function viewCapacity(User $user): bool
    {
        return $user->hasPermission('projects.capacity');
    }

    public function manageCost(User $user): bool
    {
        return $user->hasPermission('projects.financials');
    }
}
