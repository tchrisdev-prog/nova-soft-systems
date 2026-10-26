<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/** La persona y la fecha de inicio no se cambian: se termina y se crea otra. */
class UpdateAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAllocations', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'allocation_percent' => ['sometimes', 'integer', 'min:5', 'max:100', 'multiple_of:5'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'role_label' => ['sometimes', 'nullable', 'string', 'max:80'],
            'user_id' => ['prohibited'],
            'start_date' => ['prohibited'],
            'confirm_overallocation' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function allocationData(): array
    {
        return collect($this->validated())->except('confirm_overallocation')->all();
    }
}
