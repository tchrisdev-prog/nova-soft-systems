<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Enums\ProjectKind;
use Illuminate\Validation\Rule;

/**
 * Reglas compartidas entre crear y editar proyectos. El presupuesto llega en
 * horas desde el formulario y se guarda en minutos.
 */
trait ProjectRules
{
    /** @return array<string, list<mixed>> */
    protected function projectRules(bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:3', 'max:150'],
            'kind' => [$required, Rule::enum(ProjectKind::class)],
            'site_id' => [$required, 'integer'],
            'start_date' => [$required, 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'budget_hours' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:100000', 'multiple_of:0.25'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    public function projectData(): array
    {
        $data = $this->validated();

        if (array_key_exists('budget_hours', $data)) {
            $data['budget_minutes'] = $data['budget_hours'] === null ? null : (int) round(((float) $data['budget_hours']) * 60);
            unset($data['budget_hours']);
        }

        return $data;
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'kind' => 'tipo',
            'site_id' => 'sede',
            'start_date' => 'fecha de inicio',
            'end_date' => 'fecha de fin',
            'budget_hours' => 'presupuesto de horas',
            'contract_id' => 'contrato',
            'leader_id' => 'líder',
        ];
    }
}
