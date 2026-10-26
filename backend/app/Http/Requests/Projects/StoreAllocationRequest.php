<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

class StoreAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAllocations', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'allocation_percent' => ['required', 'integer', 'min:5', 'max:100', 'multiple_of:5'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'role_label' => ['nullable', 'string', 'max:80'],
            // Confirmación explícita cuando la persona queda por encima del 100 %.
            'confirm_overallocation' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function allocationData(): array
    {
        return collect($this->validated())->except('confirm_overallocation')->all();
    }
}
