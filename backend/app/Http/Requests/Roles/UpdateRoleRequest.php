<?php

namespace App\Http\Requests\Roles;

use App\Models\Role;

class UpdateRoleRequest extends RoleRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->currentRole());
    }

    protected function currentRole(): ?Role
    {
        return $this->route('role');
    }
}
