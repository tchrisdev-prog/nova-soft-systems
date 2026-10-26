<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Permiso granular: una acción dentro de un módulo. El código
 * "<modulo>.<accion>" es único y es el identificador usado en la
 * autorización (backend) y en la interfaz (frontend).
 *
 * @property int $id
 * @property int $module_id
 * @property string $action
 * @property string $code
 * @property string|null $description
 */
class Permission extends Model
{
    /**
     * Los permisos provienen del catálogo (config/nova.php) y se sincronizan
     * por comando; no se crean desde la API.
     *
     * @var list<string>
     */
    protected $fillable = [];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public static function codeFor(string $moduleCode, string $action): string
    {
        return $moduleCode.'.'.$action;
    }
}
