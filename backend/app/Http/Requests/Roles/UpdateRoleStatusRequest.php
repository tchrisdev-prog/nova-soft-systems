<?php

namespace App\Http\Requests\Roles;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('role'));
    }

    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }

    public function messages(): array
    {
        return ['is_active.required' => 'Indica el nuevo estado del rol.', 'is_active.boolean' => 'El estado debe ser verdadero o falso.'];
    }
}
