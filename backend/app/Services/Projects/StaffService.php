<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Exceptions\BusinessRuleException;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Crea o actualiza el perfil laboral de una persona. Quien entra por primera
 * vez con Microsoft 365 no tiene perfil, y hasta que se le cree no aparece en
 * la capacidad (no hay forma honesta de adivinar su sede o su jornada).
 */
class StaffService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{site_id: int, position: string, specialty?: string|null, weekly_hours: int|float|string, hourly_cost?: int|null}  $data
     */
    public function save(User $actor, User $person, array $data): StaffProfile
    {
        if (! $person->is_active) {
            throw new BusinessRuleException('La cuenta de esta persona está desactivada.', 'inactive_user', 422);
        }

        return DB::transaction(function () use ($actor, $person, $data) {
            $profile = $person->staffProfile()->first() ?? new StaffProfile;
            $isNew = ! $profile->exists;

            $profile->fill([
                'position' => trim($data['position']),
                'specialty' => $data['specialty'] ?? null,
            ]);
            $profile->forceFill([
                'user_id' => $person->getKey(),
                'site_id' => (int) $data['site_id'],
                'weekly_capacity_minutes' => (int) round(((float) $data['weekly_hours']) * 60),
            ]);

            if (array_key_exists('hourly_cost', $data)) {
                $profile->forceFill(['hourly_cost' => $data['hourly_cost']]);
            }

            $changes = [];
            foreach ($profile->getDirty() as $field => $value) {
                if (in_array($field, ['user_id', 'created_at', 'updated_at'], true)) {
                    continue;
                }
                $old = $profile->getOriginal($field);
                $new = $profile->getAttribute($field);
                $changes[$field] = [
                    'old' => $old instanceof \BackedEnum ? $old->value : $old,
                    'new' => $new instanceof \BackedEnum ? $new->value : $new,
                ];
            }

            // El valor del costo no se copia a la auditoría: basta con saber
            // que cambió y quién lo cambió.
            if (isset($changes['hourly_cost'])) {
                $changes['hourly_cost'] = ['changed' => true];
            }

            if ($changes === [] && ! $isNew) {
                return $profile;
            }

            $profile->save();
            $this->audit->log($isNew ? 'staff_profile.created' : 'staff_profile.updated', $actor, $profile, [
                'user_id' => $person->getKey(),
                'changes' => $changes,
            ]);

            return $profile;
        });
    }
}
