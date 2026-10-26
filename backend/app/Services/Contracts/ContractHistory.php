<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\ContractRevision;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * Escribe el historial de un contrato. Se llama siempre dentro de la misma
 * transacción que el cambio: si el cambio se revierte, la línea del historial
 * también, y si el cambio se guarda, su línea queda guardada con él.
 */
class ContractHistory
{
    public function __construct(private readonly ?Request $request = null) {}

    public function record(Contract $contract, ?User $actor, string $event, array $changes = [], ?string $note = null): ContractRevision
    {
        $revision = new ContractRevision;
        $revision->forceFill([
            'contract_id' => $contract->getKey(),
            'actor_id' => $actor?->getKey(),
            'event' => $event,
            'changes' => $changes ? self::normalize($changes) : null,
            'note' => $note,
            'ip_address' => $this->request?->ip(),
            'created_at' => now(),
        ])->save();

        return $revision;
    }

    /**
     * Compara los atributos sucios de un modelo con sus valores originales y
     * devuelve solo lo que cambió, en formato { campo: { antes, después } }.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function diff(Contract $contract): array
    {
        $diff = [];

        foreach (array_keys($contract->getDirty()) as $field) {
            if (in_array($field, ['updated_at', 'updated_by'], true)) {
                continue;
            }

            $diff[$field] = [
                'old' => $contract->getOriginal($field),
                'new' => $contract->getAttribute($field),
            ];
        }

        return $diff;
    }

    /** Fechas y Enums se guardan como texto plano para que el JSON sea legible. */
    private static function normalize(array $value): array
    {
        array_walk_recursive($value, function (&$item): void {
            $item = match (true) {
                $item instanceof BackedEnum => $item->value,
                $item instanceof DateTimeInterface => $item->format('Y-m-d'),
                default => $item,
            };
        });

        return $value;
    }
}
