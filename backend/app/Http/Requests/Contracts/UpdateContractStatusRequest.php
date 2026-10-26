<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use App\Enums\ContractStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContractStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('changeStatus', $this->route('contract'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ContractStatus::class)],
            // Suspender o cancelar siempre necesita una razón: es lo primero
            // que alguien pregunta cuando revisa el historial.
            'note' => [
                Rule::requiredIf(in_array($this->input('status'), [ContractStatus::Suspended->value, ContractStatus::Cancelled->value], true)),
                'nullable', 'string', 'min:5', 'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return ['note.required' => 'Indica el motivo para suspender o cancelar el contrato.'];
    }

    public function status(): ContractStatus
    {
        return ContractStatus::from($this->validated('status'));
    }
}
