<?php

namespace App\Http\Requests\Roles;

use App\Models\Role;
use App\Services\Roles\RoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validación compartida para crear y editar roles.
 * - Nombre único sin distinguir mayúsculas.
 * - El slug derivado (identificador estable) también debe ser único y no
 *   puede coincidir con el del rol de sistema.
 */
abstract class RoleRequest extends FormRequest
{
    abstract protected function currentRole(): ?Role;

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? preg_replace('/\s+/u', ' ', trim($this->input('name'))) : $this->input('name'),
            'description' => is_string($this->input('description')) ? (trim($this->input('description')) ?: null) : $this->input('description'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:80', 'regex:/^[\pL\pN][\pL\pN\s\-_.()&]*$/u'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del rol es obligatorio.',
            'name.min' => 'El nombre debe tener al menos :min caracteres.',
            'name.max' => 'El nombre no puede superar :max caracteres.',
            'name.regex' => 'El nombre solo admite letras, números, espacios y los símbolos - _ . ( ) &.',
            'description.max' => 'La descripción no puede superar :max caracteres.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('name')) {
                return;
            }

            $name = (string) $this->input('name');
            $current = $this->currentRole();

            $nameTaken = Role::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->when($current, fn ($q) => $q->whereKeyNot($current->getKey()))
                ->exists();

            if ($nameTaken) {
                $validator->errors()->add('name', 'Ya existe un rol con ese nombre.');

                return;
            }

            if ($current) {
                return; // El slug no cambia al renombrar.
            }

            $slug = RoleService::slugFor($name);

            if ($slug === config('nova.super_admin_role') || Role::query()->where('slug', $slug)->exists()) {
                $validator->errors()->add('name', 'Ya existe un rol con un nombre equivalente.');
            }
        }];
    }
}
