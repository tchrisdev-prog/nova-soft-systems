<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Dos formas de ver proyectos:
 * - projects.view: todos (Gerencia, Comercial).
 * - projects.lead: solo los que la persona lidera (Líder de proyecto).
 *
 * Editar datos del proyecto (presupuesto, fechas, sede) es de projects.update;
 * el líder gestiona el equipo y el estado, pero no su propio presupuesto.
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('projects.view') || $user->hasPermission('projects.lead');
    }

    public function view(User $user, Project $project): bool
    {
        return $user->hasPermission('projects.view')
            || ($user->hasPermission('projects.lead') && $project->isLedBy($user));
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('projects.create');
    }

    public function update(User $user, Project $project): bool
    {
        return $user->hasPermission('projects.update');
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->hasPermission('projects.delete');
    }

    /**
     * Iniciar, pausar o cerrar. El líder lo hace con su propio proyecto: es
     * quien sabe cuándo arrancó o terminó el trabajo.
     */
    public function changeStatus(User $user, Project $project): bool
    {
        return $user->hasPermission('projects.update')
            || ($user->hasPermission('projects.lead') && $project->isLedBy($user));
    }

    /** Cambiar el líder no lo decide el líder actual. */
    public function changeLeader(User $user, Project $project): bool
    {
        return $user->hasPermission('projects.manage') || $user->hasPermission('projects.update');
    }

    /** Asignar o retirar consultores. */
    public function manageAllocations(User $user, Project $project): bool
    {
        return $user->hasPermission('projects.manage')
            || ($user->hasPermission('projects.lead') && $project->isLedBy($user));
    }

    public function viewFinancials(User $user, ?Project $project = null): bool
    {
        return $user->hasPermission('projects.financials');
    }
}
