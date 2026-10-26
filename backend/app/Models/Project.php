<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\CalendarDate;
use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $contract_id
 * @property string $code
 * @property string $name
 * @property ProjectKind $kind
 * @property ProjectStatus $status
 * @property int|null $leader_id
 * @property int $site_id
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property int|null $budget_minutes
 */
class Project extends Model
{
    protected $fillable = [
        'name',
        'kind',
        'start_date',
        'end_date',
        'budget_minutes',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ProjectKind::class,
            'status' => ProjectStatus::class,
            'start_date' => CalendarDate::class,
            'end_date' => CalendarDate::class,
            'budget_minutes' => 'integer',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id')->withTrashed();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function isLedBy(User $user): bool
    {
        return $this->leader_id !== null && $this->leader_id === $user->getKey();
    }

    public function scopeLedBy(Builder $query, User $user): Builder
    {
        return $query->where('leader_id', $user->getKey());
    }
}
