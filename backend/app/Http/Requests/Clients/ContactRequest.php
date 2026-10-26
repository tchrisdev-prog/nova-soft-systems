<?php

declare(strict_types=1);

namespace App\Http\Requests\Clients;

use Illuminate\Foundation\Http\FormRequest;

/** Agregar o editar un contacto exige poder editar el cliente. */
class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('client'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ContactRules::normalize($this->all()));
    }

    public function rules(): array
    {
        return ContactRules::for(partial: $this->isMethod('PUT'));
    }

    public function messages(): array
    {
        return ContactRules::messages();
    }
}
