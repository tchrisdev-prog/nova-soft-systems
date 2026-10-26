<?php

namespace App\Http\Requests\Roles;

use Illuminate\Foundation\Http\FormRequest;

class SyncRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('managePermissions', $this->route('role'));
    }

    public function rules(): array
    {
        return [
            'permission_ids' => ['present', 'array', 'max:500'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'permission_ids.present' => 'Envía la lista de permisos (puede estar vacía).',
            'permission_ids.*.exists' => 'Uno de los permisos seleccionados no existe.',
            'permission_ids.*.distinct' => 'Hay permisos repetidos en la selección.',
        ];
    }

    /** @return list<int> */
    public function permissionIds(): array
    {
        return array_map('intval', $this->validated('permission_ids'));
    }
}
