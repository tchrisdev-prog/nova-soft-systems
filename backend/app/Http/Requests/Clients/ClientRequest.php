<?php

declare(strict_types=1);

namespace App\Http\Requests\Clients;

use App\Enums\ClientSector;
use App\Models\Client;
use App\Rules\ColombianNit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos de un cliente. El código se genera al crearlo y no cambia después:
 * es la referencia que se usa en contratos, proyectos y reportes.
 */
abstract class ClientRequest extends FormRequest
{
    abstract protected function currentClient(): ?Client;

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }

        if (is_string($this->input('tax_id'))) {
            $this->merge(['tax_id' => ColombianNit::normalize($this->input('tax_id')) ?: null]);
        }

        if (is_array($this->input('contacts'))) {
            $this->merge(['contacts' => array_map(
                fn ($c) => is_array($c) ? ContactRules::normalize($c) : $c,
                $this->input('contacts'),
            )]);
        }
    }

    public function rules(): array
    {
        $current = $this->currentClient();
        $required = $current ? 'sometimes' : 'required';

        return [
            // El código lo genera el sistema (CLI-001). Se acepta uno propio
            // solo al crear, por ejemplo al importar clientes existentes.
            'code' => $current
                ? ['prohibited']
                : ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9][A-Z0-9-]{1,29}$/', 'unique:clients,code'],
            'name' => [$required, 'string', 'min:2', 'max:150'],
            'tax_id' => ['nullable', 'string', new ColombianNit, Rule::unique('clients', 'tax_id')->ignore($current?->getKey())],
            'sector' => [$required, Rule::enum(ClientSector::class)],
            'site_id' => [$required, 'integer', Rule::exists('sites', 'id')->where('is_active', true)],
            'is_active' => $current ? ['sometimes', 'boolean'] : ['prohibited'],
            // Los contactos se pueden enviar al crear el cliente; después se
            // gestionan uno por uno en /clients/{id}/contacts.
            'contacts' => $current ? ['prohibited'] : ['sometimes', 'array', 'max:20'],
            ...($current ? [] : ContactRules::for('contacts.*.')),
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'El código solo admite letras mayúsculas, números y guiones (por ejemplo, CLI-001).',
            'code.prohibited' => 'El código del cliente no se puede cambiar.',
            'tax_id.unique' => 'Ya hay un cliente registrado con ese NIT.',
            'contacts.prohibited' => 'Los contactos se editan uno por uno desde el detalle del cliente.',
            ...ContactRules::messages('contacts.*.'),
        ];
    }
}
