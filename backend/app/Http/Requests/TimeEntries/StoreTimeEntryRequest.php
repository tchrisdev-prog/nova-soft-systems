<?php

declare(strict_types=1);

namespace App\Http\Requests\TimeEntries;

use App\Models\TimeEntry;

class StoreTimeEntryRequest extends TimeEntryRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', TimeEntry::class);
    }

    protected function isUpdate(): bool
    {
        return false;
    }
}
