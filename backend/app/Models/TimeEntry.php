<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\CalendarDate;
use App\Enums\TimeEntryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un bloque de trabajo de un consultor en un proyecto.
 *
 * @property int $id
 * @property int $user_id
 * @property int $project_id
 * @property Carbon $work_date
 * @property int $minutes
 * @property string $description
 * @property bool $is_billable
 * @property bool $is_after_hours
 * @property bool $is_on_call
 * @property TimeEntryStatus $status
 * @property int|null $reviewed_by
 * @property string|null $rejection_reason
 * @property int|null $cost_rate
 */
class TimeEntry extends Model
{
    protected $fillable = [
        'work_date',
        'minutes',
        'description',
        'is_billable',
        'is_after_hours',
        'is_on_call',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => CalendarDate::class,
            'minutes' => 'integer',
            'is_billable' => 'boolean',
            'is_after_hours' => 'boolean',
            'is_on_call' => 'boolean',
            'status' => TimeEntryStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'cost_rate' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->getKey();
    }
}
