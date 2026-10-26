<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Registra operaciones administrativas sensibles. Nunca guarda secretos,
 * tokens ni datos que no sean necesarios para la trazabilidad.
 */
class AuditLogger
{
    public function __construct(private readonly ?Request $request = null) {}

    public function log(string $action, ?User $actor = null, ?Model $subject = null, array $metadata = []): AuditLog
    {
        $log = new AuditLog;
        $log->forceFill([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'metadata' => $metadata ?: null,
            'ip_address' => $this->request?->ip(),
            'user_agent' => $this->request ? Str::limit((string) $this->request->userAgent(), 250, '') : null,
            'created_at' => now(),
        ])->save();

        return $log;
    }
}
