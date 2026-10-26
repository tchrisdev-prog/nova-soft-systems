<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\CalendarDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Asignación de un consultor a un proyecto con un % de su capacidad.
 *
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property string|null $role_label
 * @property int $allocation_percent
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 */
class Allocation extends Model
{
    protected $fillable = ['role_label', 'allocation_percent', 'start_date', 'end_date'];

    protected function casts(): array
    {
        return [
            'allocation_percent' => 'integer',
            'start_date' => CalendarDate::class,
            'end_date' => CalendarDate::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** Asignaciones vigentes en algún momento entre $from y $to (ambos incluidos). */
    public function scopeOverlapping(Builder $query, string $from, ?string $to): Builder
    {
        return $query
            ->when($to !== null, fn (Builder $q) => $q->where('start_date', '<=', $to))
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $from));
    }

    /** Asignaciones vigentes en una fecha puntual. */
    public function scopeActiveOn(Builder $query, string $date): Builder
    {
        return $query->overlapping($date, $date);
    }
}
