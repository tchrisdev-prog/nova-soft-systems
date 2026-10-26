<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('users.view');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->is($target) || $actor->hasPermission('users.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('users.create');
    }

    public function updateStatus(User $actor, User $target): bool
    {
        return $actor->hasPermission('users.update');
    }

    public function assignRoles(User $actor, User $target): bool
    {
        return $actor->hasPermission('users.manage');
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->hasPermission('users.delete');
    }
}
