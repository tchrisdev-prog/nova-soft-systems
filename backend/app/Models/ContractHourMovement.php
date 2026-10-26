<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\CalendarDate;
use App\Enums\HourMovementType;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Línea del "extracto" de una bolsa de horas.
 *
 * @property int $id
 * @property int $contract_id
 * @property HourMovementType $type
 * @property int $minutes
 * @property int $amount
 * @property Carbon $reference_date
 * @property string|null $note
 */
class ContractHourMovement extends Model
{
    use Immutable;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'type' => HourMovementType::class,
            'minutes' => 'integer',
            'amount' => 'integer',
            'reference_date' => CalendarDate::class,
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
