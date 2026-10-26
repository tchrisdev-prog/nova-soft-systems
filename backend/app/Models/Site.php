<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sede de la empresa (Medellín, Bogotá o Remoto).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $city
 * @property bool $is_active
 */
class Site extends Model
{
    /** Las sedes se crean con nova:setup-projects, no desde la API. */
    protected $fillable = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function staffProfiles(): HasMany
    {
        return $this->hasMany(StaffProfile::class);
    }
}
