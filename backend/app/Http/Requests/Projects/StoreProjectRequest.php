<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    use ProjectRules;

    public function authorize(): bool
    {
        return $this->user()->can('create', Project::class);
    }

    public function rules(): array
    {
        return $this->projectRules(partial: false) + [
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
            'leader_id' => ['nullable', 'integer'],
        ];
    }
}
