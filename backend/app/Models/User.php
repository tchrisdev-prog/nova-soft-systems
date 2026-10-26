<?php

namespace App\Models;

use App\Enums\ModuleStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

/**
 * Usuario corporativo. No almacena contraseñas: la identidad la confirma
 * Microsoft Entra ID y aquí solo se gestiona la autorización.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $microsoft_oid
 * @property string|null $microsoft_tenant_id
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Solo atributos descriptivos son asignables de forma masiva.
     * Estado, identificadores de Microsoft y roles se gestionan
     * explícitamente desde los servicios.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
    ];

    /** Caché por instancia de los códigos de permiso efectivos. */
    private ?Collection $resolvedPermissionCodes = null;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)),
        );
    }

    /**
     * No existe contraseña local. Se devuelve una cadena vacía para que los
     * componentes de sesión de Laravel/Sanctum (que comparan un hash de la
     * contraseña para invalidar sesiones) funcionen sin una columna "password".
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    /** Sin "recordarme": la duración de la sesión la controla SESSION_LIFETIME. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withPivot(['assigned_by', 'created_at']);
    }

    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function ledProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'leader_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Cuentas que tienen un permiso por alguno de sus roles activos (o por ser
     * Super Admin). Es la misma regla de permissionCodes(), pero en SQL, para
     * listar candidatos sin cargar a cada usuario.
     */
    public function scopeWithPermission(Builder $query, string $code): Builder
    {
        $superAdmin = config('nova.super_admin_role');

        return $query->whereHas('roles', fn (Builder $r) => $r
            ->where('roles.is_active', true)
            ->where(fn (Builder $w) => $w
                ->where('roles.slug', $superAdmin)
                ->orWhereHas('permissions', fn (Builder $p) => $p
                    ->where('code', $code)
                    ->whereHas('module', fn (Builder $m) => $m->where('status', '!=', ModuleStatus::Inactive->value)))));
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
            ->orWhereRaw('LOWER(email) LIKE ?', [$like]));
    }

    /**
     * Tiene asignado el rol de sistema Super Admin (activo), sin considerar
     * si la cuenta está activa. Útil para reglas de protección.
     */
    public function hasSuperAdminRole(): bool
    {
        $slug = config('nova.super_admin_role');

        return $this->relationLoaded('roles')
            ? $this->roles->contains(fn (Role $role) => $role->slug === $slug && $role->is_active)
            : $this->roles()->where('slug', $slug)->where('is_active', true)->exists();
    }

    /** Es Super Admin efectivo: cuenta activa y rol Super Admin asignado. */
    public function isSuperAdmin(): bool
    {
        return $this->is_active && $this->hasSuperAdminRole();
    }

    /**
     * Permisos efectivos ("modulo.accion") de una cuenta activa. Una cuenta
     * inactiva no tiene permisos.
     *
     * @return Collection<int, string>
     */
    public function permissionCodes(): Collection
    {
        if (! $this->is_active) {
            return collect();
        }

        return $this->resolvedPermissionCodes ??= $this->grantedPermissionCodes();
    }

    /**
     * Permisos otorgados por los roles activos asignados, aunque la cuenta
     * esté desactivada. Se usa para impedir que alguien reactive o gestione
     * cuentas con más privilegios que los propios. Excluye módulos inactivos.
     *
     * @return Collection<int, string>
     */
    public function grantedPermissionCodes(): Collection
    {
        $query = Permission::query()
            ->whereHas('module', fn (Builder $q) => $q->where('status', '!=', ModuleStatus::Inactive->value));

        if (! $this->hasSuperAdminRole()) {
            $query->whereHas('roles', fn (Builder $q) => $q
                ->where('roles.is_active', true)
                ->whereHas('users', fn (Builder $u) => $u->whereKey($this->getKey())));
        }

        return $query->orderBy('code')->pluck('code')->values();
    }

    public function hasPermission(string $code): bool
    {
        return $this->permissionCodes()->contains($code);
    }

    /**
     * Acceso a un módulo: debe estar activo y el usuario debe tener al menos
     * un permiso dentro de él.
     */
    public function canAccessModule(string $moduleCode): bool
    {
        $module = Module::query()->where('code', $moduleCode)->first();

        if (! $module || $module->status !== ModuleStatus::Active) {
            return false;
        }

        return $this->permissionCodes()->contains(fn (string $code) => str_starts_with($code, $moduleCode.'.'));
    }

    /** Olvida los permisos calculados (tras cambios de roles en la misma petición). */
    public function flushPermissionCache(): void
    {
        $this->resolvedPermissionCodes = null;
        $this->unsetRelation('roles');
    }
}
