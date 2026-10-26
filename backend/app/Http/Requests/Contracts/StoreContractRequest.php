<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use App\Enums\BillingModel;
use App\Enums\Coverage;
use App\Enums\ServiceLine;
use App\Models\Contract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Un contrato nace en borrador. Según la forma de cobro pide datos distintos:
 * - Bolsa de horas: horas iniciales y su valor.
 * - Mensualidad: valor mensual y, si aplica, horas incluidas al mes
 *   (vacío = el servicio se cobra por disponibilidad).
 */
class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Contract::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $hourBag = BillingModel::HourBag->value;
        $monthly = BillingModel::MonthlyRecurring->value;
        $step = (int) config('nova.timesheets.step_minutes', 15);

        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            // Lo genera el sistema (CT-2026-001); solo se acepta uno propio para importaciones.
            'code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9][A-Z0-9-]{1,29}$/', 'unique:contracts,code'],
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'service_line' => ['required', Rule::enum(ServiceLine::class)],
            'billing_model' => ['required', Rule::enum(BillingModel::class)],
            'coverage' => ['required', Rule::enum(Coverage::class)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'monthly_fee' => ["required_if:billing_model,{$monthly}", "prohibited_unless:billing_model,{$monthly}", 'nullable', 'integer', 'min:0', 'max:99999999999'],
            'included_minutes_per_month' => ["prohibited_unless:billing_model,{$monthly}", 'nullable', 'integer', 'min:0', 'max:44640', "multiple_of:{$step}"],
            'initial_minutes' => ["required_if:billing_model,{$hourBag}", "prohibited_unless:billing_model,{$hourBag}", 'nullable', 'integer', 'min:'.$step, 'max:6000000', "multiple_of:{$step}"],
            'initial_amount' => ["required_if:billing_model,{$hourBag}", "prohibited_unless:billing_model,{$hourBag}", 'nullable', 'integer', 'min:0', 'max:99999999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'El código solo admite letras mayúsculas, números y guiones (por ejemplo, CT-2026-001).',
            'monthly_fee.required_if' => 'Una mensualidad necesita su valor mensual.',
            'initial_minutes.required_if' => 'Una bolsa de horas necesita las horas iniciales.',
            'initial_amount.required_if' => 'Indica el valor de la bolsa inicial.',
        ];
    }
}
