<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use App\Enums\HourMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recarga o ajuste de una bolsa. Una recarga suma horas; un ajuste corrige
 * (puede restar) y siempre exige explicar el motivo.
 */
class StoreHourMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('addHours', $this->route('contract'));
    }

    public function rules(): array
    {
        $step = (int) config('nova.timesheets.step_minutes', 15);
        $isTopUp = $this->input('type') === HourMovementType::TopUp->value;

        return [
            'type' => ['required', Rule::in([HourMovementType::TopUp->value, HourMovementType::Adjustment->value])],
            'minutes' => ['required', 'integer', 'not_in:0', $isTopUp ? 'min:'.$step : 'min:-6000000', 'max:6000000', "multiple_of:{$step}"],
            'amount' => ['required', 'integer', $isTopUp ? 'min:0' : 'min:-99999999999', 'max:99999999999'],
            'reference_date' => ['required', 'date_format:Y-m-d'],
            'note' => [Rule::requiredIf(! $isTopUp), 'nullable', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'El movimiento debe ser una recarga o un ajuste.',
            'minutes.not_in' => 'El movimiento debe sumar o restar horas.',
            'note.required' => 'Explica el motivo del ajuste.',
        ];
    }

    public function movementType(): HourMovementType
    {
        return HourMovementType::from($this->validated('type'));
    }
}
