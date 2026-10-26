<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class SyncUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assignRoles', $this->route('user'));
    }

    public function rules(): array
    {
        return [
            'role_ids' => ['present', 'array', 'max:50'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'role_ids.present' => 'Envía la lista de roles (puede estar vacía).',
            'role_ids.*.exists' => 'Uno de los roles seleccionados no existe.',
        ];
    }

    /** @return list<int> */
    public function roleIds(): array
    {
        return array_map('intval', $this->validated('role_ids'));
    }
}
