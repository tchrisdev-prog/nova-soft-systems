<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Enums\ProjectStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('changeStatus', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            // Pausar necesita una razón: es lo primero que pregunta el cliente.
            'note' => [
                Rule::requiredIf($this->input('status') === ProjectStatus::OnHold->value),
                'nullable', 'string', 'min:5', 'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return ['note.required' => 'Indica el motivo para pausar el proyecto.'];
    }

    public function status(): ProjectStatus
    {
        return ProjectStatus::from($this->validated('status'));
    }
}
