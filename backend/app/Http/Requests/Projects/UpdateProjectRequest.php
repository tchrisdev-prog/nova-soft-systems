<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/** El contrato y el líder no se cambian aquí (el líder tiene su propia ruta). */
class UpdateProjectRequest extends FormRequest
{
    use ProjectRules;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('project'));
    }

    public function rules(): array
    {
        return $this->projectRules(partial: true) + [
            'contract_id' => ['prohibited'],
            'leader_id' => ['prohibited'],
        ];
    }
}
