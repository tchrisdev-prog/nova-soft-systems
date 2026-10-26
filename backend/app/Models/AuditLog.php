<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Registro inmutable de operaciones sensibles.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $action
 * @property array|null $metadata
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Carga quién hizo cada operación y sobre qué, incluyendo usuarios ya
     * eliminados: la auditoría debe seguir mostrando sus nombres.
     */
    public function scopeWithPeople(Builder $query): Builder
    {
        return $query->with([
            'actor',
            'auditable' => fn (MorphTo $morph) => $morph->constrain([
                User::class => fn (Builder $q) => $q->withTrashed(),
            ]),
        ]);
    }
}
