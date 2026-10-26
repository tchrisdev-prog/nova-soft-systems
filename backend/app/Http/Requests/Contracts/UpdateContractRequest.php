<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use App\Enums\Coverage;
use App\Enums\ServiceLine;
use App\Models\Contract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Edición de un contrato. El cliente, el código y la forma de cobro no se
 * cambian: si cambian, en realidad es un contrato nuevo.
 */
class UpdateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->contract());
    }

    public function rules(): array
    {
        $step = (int) config('nova.timesheets.step_minutes', 15);

        return [
            'client_id' => ['prohibited'],
            'code' => ['prohibited'],
            'billing_model' => ['prohibited'],
            'name' => ['sometimes', 'string', 'min:3', 'max:150'],
            'service_line' => ['sometimes', Rule::enum(ServiceLine::class)],
            'coverage' => ['sometimes', Rule::enum(Coverage::class)],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'monthly_fee' => ['sometimes', 'integer', 'min:0', 'max:99999999999'],
            'included_minutes_per_month' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:44640', "multiple_of:{$step}"],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // Por qué se hace el cambio; queda en el historial del contrato.
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.prohibited' => 'El cliente de un contrato no se puede cambiar.',
            'code.prohibited' => 'El código de un contrato no se puede cambiar.',
            'billing_model.prohibited' => 'La forma de cobro no se puede cambiar. Registra un contrato nuevo.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $contract = $this->contract();

            if ($contract->isHourBag() && ($this->has('monthly_fee') || $this->has('included_minutes_per_month'))) {
                $validator->errors()->add('monthly_fee', 'Una bolsa de horas no tiene valor mensual. Usa una recarga para agregar horas.');
            }

            $start = $this->input('start_date', $contract->start_date->toDateString());
            $end = $this->has('end_date') ? $this->input('end_date') : $contract->end_date?->toDateString();

            if ($end !== null && is_string($start) && is_string($end) && $end < $start) {
                $validator->errors()->add('end_date', 'La fecha de fin debe ser igual o posterior a la de inicio.');
            }
        }];
    }

    public function contract(): Contract
    {
        return $this->route('contract');
    }

    /** @return array<string, mixed> */
    public function contractData(): array
    {
        return collect($this->validated())->except(['note'])->all();
    }
}
