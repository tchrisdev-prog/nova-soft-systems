<?php

declare(strict_types=1);

namespace App\Http\Requests\TimeEntries;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lote de registros para enviar, aprobar o rechazar. La autorización se
 * revisa registro por registro en TimeEntryService (todo o nada).
 */
class TimeEntryBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct'],
            'reason' => [$this->routeIs('*.reject') ? 'required' : 'prohibited', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Explica el motivo del rechazo para que el consultor pueda corregirlo.',
        ];
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_values(array_map('intval', $this->validated('ids')));
    }
}
