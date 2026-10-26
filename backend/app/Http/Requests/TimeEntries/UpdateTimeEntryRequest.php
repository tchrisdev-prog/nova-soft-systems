<?php

declare(strict_types=1);

namespace App\Http\Requests\TimeEntries;

class UpdateTimeEntryRequest extends TimeEntryRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('timeEntry'));
    }

    protected function isUpdate(): bool
    {
        return true;
    }
}
