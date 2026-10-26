<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContactType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persona de contacto de un cliente. Son datos personales de terceros:
 * solo los ve quien tiene clients.view.
 *
 * @property int $id
 * @property int $client_id
 * @property ContactType $type
 * @property string $name
 * @property string|null $position
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_primary
 */
class ClientContact extends Model
{
    protected $fillable = ['type', 'name', 'position', 'email', 'phone'];

    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
            'is_primary' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
