<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsBusinessData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ContractHourMovement */
class HourMovementResource extends JsonResource
{
    use FormatsBusinessData;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->enumValue($this->type),
            'minutes' => $this->minutes,
            'amount' => $this->amount,
            'reference_date' => $this->reference_date?->toDateString(),
            'note' => $this->note,
            'author' => $this->whenLoaded('author', fn () => $this->author ? ['id' => $this->author->id, 'name' => $this->author->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
