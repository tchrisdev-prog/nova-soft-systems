<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\CalendarDate;
use App\Enums\BillingModel;
use App\Enums\ContractStatus;
use App\Enums\Coverage;
use App\Enums\ServiceLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Contrato con un cliente. El tipo de cobro decide cómo se mide el consumo:
 * una bolsa de horas se descuenta, una mensualidad se mide mes a mes.
 *
 * @property int $id
 * @property int $client_id
 * @property string $code
 * @property string $name
 * @property ServiceLine $service_line
 * @property BillingModel $billing_model
 * @property Coverage $coverage
 * @property ContractStatus $status
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property int|null $monthly_fee
 * @property int|null $included_minutes_per_month
 * @property string|null $notes
 */
class Contract extends Model
{
    /**
     * Solo lo descriptivo y las condiciones económicas. El estado y el tipo de
     * cobro se manejan desde ContractService para que todo cambio pase por
     * las reglas y quede en el historial.
     */
    protected $fillable = [
        'name',
        'service_line',
        'coverage',
        'start_date',
        'end_date',
        'monthly_fee',
        'included_minutes_per_month',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'service_line' => ServiceLine::class,
            'billing_model' => BillingModel::class,
            'coverage' => Coverage::class,
            'status' => ContractStatus::class,
            'start_date' => CalendarDate::class,
            'end_date' => CalendarDate::class,
            'monthly_fee' => 'integer',
            'included_minutes_per_month' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function hourMovements(): HasMany
    {
        return $this->hasMany(ContractHourMovement::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(ContractPeriod::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ContractRevision::class);
    }

    public function isHourBag(): bool
    {
        return $this->billing_model === BillingModel::HourBag;
    }

    public function isMonthly(): bool
    {
        return $this->billing_model === BillingModel::MonthlyRecurring;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.mb_strtolower($term).'%';

        return $query->where(fn (Builder $q) => $q
            ->whereRaw('LOWER(contracts.name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(contracts.code) LIKE ?', [$like]));
    }
}
