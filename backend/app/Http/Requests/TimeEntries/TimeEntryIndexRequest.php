<?php

declare(strict_types=1);

namespace App\Http\Requests\TimeEntries;

use App\Enums\TimeEntryStatus;
use App\Models\TimeEntry;
use App\Services\Timesheets\TimeEntryQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TimeEntryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', TimeEntry::class)
            && app(TimeEntryQuery::class)->canUseScope($this->user(), $this->scope());
    }

    public function rules(): array
    {
        return [
            'scope' => ['sometimes', Rule::in(TimeEntryQuery::SCOPES)],
            'project_id' => ['sometimes', 'integer'],
            'user_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::enum(TimeEntryStatus::class)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'is_after_hours' => ['sometimes', 'boolean'],
            'is_on_call' => ['sometimes', 'boolean'],
            'is_billable' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function scope(): string
    {
        $scope = $this->input('scope', 'mine');

        return is_string($scope) ? $scope : 'mine';
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return collect($this->validated())->except(['scope', 'per_page', 'page'])->all();
    }
}
