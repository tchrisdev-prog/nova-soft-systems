<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateStatus', $this->route('user'));
    }

    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }

    public function messages(): array
    {
        return ['is_active.required' => 'Indica el nuevo estado del usuario.'];
    }
}
