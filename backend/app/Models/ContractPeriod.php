<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\CalendarDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un mes de una mensualidad, con las condiciones que regían ese mes.
 *
 * @property int $id
 * @property int $contract_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $fee_amount
 * @property int|null $included_minutes
 */
class ContractPeriod extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'period_start' => CalendarDate::class,
            'period_end' => CalendarDate::class,
            'fee_amount' => 'integer',
            'included_minutes' => 'integer',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
