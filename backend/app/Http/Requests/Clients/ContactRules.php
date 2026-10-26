<?php

declare(strict_types=1);

namespace App\Http\Requests\Clients;

use App\Enums\ContactType;
use Illuminate\Validation\Rule;

/** Reglas de un contacto, compartidas por el registro del cliente y la edición del contacto. */
final class ContactRules
{
    /** @return array<string, list<mixed>> */
    public static function for(string $prefix = '', bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            $prefix.'type' => [$required, Rule::enum(ContactType::class)],
            $prefix.'name' => [$required, 'string', 'min:2', 'max:120'],
            $prefix.'position' => ['nullable', 'string', 'max:100'],
            $prefix.'email' => ['nullable', 'string', 'email:rfc', 'max:150'],
            $prefix.'phone' => ['nullable', 'string', 'regex:/^[0-9+()\s-]{7,30}$/'],
            $prefix.'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(string $prefix = ''): array
    {
        return [
            $prefix.'phone.regex' => 'El teléfono solo admite números, espacios y los símbolos + ( ) -.',
            $prefix.'name.required' => 'El nombre del contacto es obligatorio.',
            $prefix.'type.required' => 'Indica el tipo de contacto.',
        ];
    }

    /** Correos en minúscula y sin espacios. */
    public static function normalize(array $contact): array
    {
        if (isset($contact['email']) && is_string($contact['email'])) {
            $contact['email'] = mb_strtolower(trim($contact['email']));
        }

        return $contact;
    }
}
