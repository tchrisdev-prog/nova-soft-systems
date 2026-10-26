<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StaffSpecialty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perfil laboral de un consultor: sede, cargo, capacidad y costo por hora.
 * El costo es información interna; solo se expone con projects.financials.
 *
 * @property int $id
 * @property int $user_id
 * @property int $site_id
 * @property string $position
 * @property StaffSpecialty|null $specialty
 * @property int $weekly_capacity_minutes
 * @property int|null $hourly_cost
 */
class StaffProfile extends Model
{
    protected $fillable = ['position', 'specialty'];

    protected function casts(): array
    {
        return [
            'specialty' => StaffSpecialty::class,
            'weekly_capacity_minutes' => 'integer',
            'hourly_cost' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
