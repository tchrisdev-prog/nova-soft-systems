<?php

namespace App\Http\Requests\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Autoriza una cuenta corporativa por correo (UPN). No solicita ni almacena
 * contraseñas: la identidad se confirma con Microsoft 365 al ingresar.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : $this->input('email'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            // Una cuenta eliminada no cuenta como duplicada: al autorizarla se recupera.
            'email' => ['required', 'string', 'email:rfc', 'max:190', Rule::unique('users', 'email')->withoutTrashed()],
            'role_ids' => ['sometimes', 'array', 'max:50'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'name.min' => 'El nombre debe tener al menos :min caracteres.',
            'email.required' => 'El correo corporativo es obligatorio.',
            'email.email' => 'Ingresa un correo válido.',
            'email.unique' => 'Esta cuenta ya está registrada.',
            'role_ids.*.exists' => 'Uno de los roles seleccionados no existe.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $allowed = config('nova.auth.allowed_email_domains', []);
            $email = (string) $this->input('email');

            if ($allowed && ! $validator->errors()->has('email')) {
                $domain = substr(strrchr($email, '@') ?: '', 1);

                if (! in_array($domain, $allowed, true)) {
                    $validator->errors()->add('email', 'El dominio del correo no está permitido para esta organización.');
                }
            }
        }];
    }
}
