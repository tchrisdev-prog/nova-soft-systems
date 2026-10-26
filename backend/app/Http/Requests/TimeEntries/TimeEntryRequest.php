<?php

declare(strict_types=1);

namespace App\Http\Requests\TimeEntries;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reglas comunes para registrar o corregir horas. Las reglas de negocio
 * (asignación, fechas permitidas, límite diario) las valida TimeEntryService.
 */
abstract class TimeEntryRequest extends FormRequest
{
    abstract protected function isUpdate(): bool;

    public function rules(): array
    {
        $required = $this->isUpdate() ? 'sometimes' : 'required';
        $step = (int) config('nova.timesheets.step_minutes', 15);

        return [
            'project_id' => [$required, 'integer', 'exists:projects,id'],
            'work_date' => [$required, 'date_format:Y-m-d'],
            'minutes' => [$required, 'integer', 'min:'.$step, 'max:1440', "multiple_of:{$step}"],
            'description' => [$required, 'string', 'min:3', 'max:500'],
            'is_billable' => ['sometimes', 'boolean'],
            'is_after_hours' => ['sometimes', 'boolean'],
            'is_on_call' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'minutes.multiple_of' => 'Las horas se registran en bloques de :value minutos.',
            'minutes.max' => 'Un registro no puede superar 24 horas.',
        ];
    }
}
