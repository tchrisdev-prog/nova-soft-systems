<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClientSector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $tax_id
 * @property ClientSector $sector
 * @property int $site_id
 * @property bool $is_active
 */
class Client extends Model
{
    protected $fillable = [
        'name',
        'tax_id',
        'sector',
    ];

    protected function casts(): array
    {
        return [
            'sector' => ClientSector::class,
            'is_active' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /** Contactos: el principal primero y los demás en el orden en que se registraron. */
    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class)->orderByDesc('is_primary')->orderBy('id');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.mb_strtolower($term).'%';

        return $query->where(fn (Builder $q) => $q
            ->whereRaw('LOWER(name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(code) LIKE ?', [$like])
            ->orWhere('tax_id', 'like', '%'.$term.'%'));
    }
}
