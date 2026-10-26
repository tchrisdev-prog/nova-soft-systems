<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ] : null),
            'subject' => $this->auditable_type ? [
                'type' => $this->auditable_type,
                'id' => $this->auditable_id,
                'label' => $this->whenLoaded('auditable', fn () => $this->subjectLabel()),
            ] : null,
            'metadata' => $this->metadata ?? (object) [],
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Texto corto para identificar el elemento auditado. No todos tienen
     * nombre (una asignación o un registro de horas no lo tienen), así que se
     * usa el nombre, el código o, en último caso, el tipo y el número.
     */
    private function subjectLabel(): ?string
    {
        $subject = $this->auditable;

        if ($subject === null) {
            return null; // el elemento ya no existe
        }

        $attributes = $subject->getAttributes();

        return $attributes['name'] ?? $attributes['code'] ?? "#{$subject->getKey()}";
    }
}
