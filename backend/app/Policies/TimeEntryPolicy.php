<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TimeEntry;
use App\Models\User;

/**
 * Reglas del registro de horas:
 *
 * - Cada quien registra, edita y envía solo sus propias horas.
 * - Una vez enviadas, ya no son del consultor: las revisa el líder del
 *   proyecto (timesheets.approve) o Gerencia/Subgerencia (timesheets.approve_any).
 * - Nadie aprueba sus propias horas, ni siquiera Gerencia. Separar quien
 *   trabaja de quien aprueba es lo que le da valor a la aprobación.
 */
class TimeEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('timesheets.create')
            || $user->hasPermission('timesheets.view')
            || $user->hasPermission('timesheets.approve')
            || $user->hasPermission('timesheets.approve_any');
    }

    public function view(User $user, TimeEntry $entry): bool
    {
        return $entry->isOwnedBy($user)
            || $user->hasPermission('timesheets.view')
            || $this->canReviewProject($user, $entry);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('timesheets.create');
    }

    public function update(User $user, TimeEntry $entry): bool
    {
        return $entry->isOwnedBy($user)
            && $user->hasPermission('timesheets.create')
            && $entry->status->isEditableByOwner();
    }

    public function delete(User $user, TimeEntry $entry): bool
    {
        return $this->update($user, $entry);
    }

    public function submit(User $user, TimeEntry $entry): bool
    {
        return $this->update($user, $entry);
    }

    /** Aprobar o rechazar. El estado "enviado" lo valida el servicio. */
    public function review(User $user, TimeEntry $entry): bool
    {
        return ! $entry->isOwnedBy($user) && $this->canReviewProject($user, $entry);
    }

    public function viewFinancials(User $user, ?TimeEntry $entry = null): bool
    {
        return $user->hasPermission('projects.financials');
    }

    private function canReviewProject(User $user, TimeEntry $entry): bool
    {
        if ($user->hasPermission('timesheets.approve_any')) {
            return true;
        }

        return $user->hasPermission('timesheets.approve') && $entry->project->isLedBy($user);
    }
}
