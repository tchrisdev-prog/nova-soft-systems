<?php

namespace App\Http\Requests\Roles;

use App\Models\Role;

class StoreRoleRequest extends RoleRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Role::class);
    }

    protected function currentRole(): ?Role
    {
        return null;
    }
}
