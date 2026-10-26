<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea del historial de un contrato: qué cambió, quién lo cambió y
 * cuándo. Se escribe en la misma transacción que el cambio.
 *
 * @property int $id
 * @property int $contract_id
 * @property int|null $actor_id
 * @property string $event
 * @property array|null $changes
 * @property string|null $note
 */
class ContractRevision extends Model
{
    use Immutable;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }
}
