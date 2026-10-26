<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Exceptions\BusinessRuleException;
use App\Models\Client;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Support\CodeGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ClientService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CodeGenerator $codes,
        private readonly ClientContactService $contacts,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(User $actor, array $data): Client
    {
        return DB::transaction(function () use ($actor, $data) {
            $client = new Client(Arr::only($data, (new Client)->getFillable()));
            $client->forceFill([
                'code' => $data['code'] ?? $this->codes->nextClientCode(),
                'site_id' => $data['site_id'],
                'is_active' => true,
                'created_by' => $actor->getKey(),
            ])->save();

            $this->audit->log('client.created', $actor, $client, ['code' => $client->code, 'name' => $client->name]);
            $this->contacts->addMany($actor, $client, $data['contacts'] ?? []);

            return $client;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $actor, Client $client, array $data): Client
    {
        return DB::transaction(function () use ($actor, $client, $data) {
            $client->fill(Arr::only($data, $client->getFillable()));

            if (array_key_exists('site_id', $data)) {
                $client->forceFill(['site_id' => $data['site_id']]);
            }

            if (array_key_exists('is_active', $data)) {
                $client->forceFill(['is_active' => (bool) $data['is_active']]);
            }

            $changes = array_keys(Arr::except($client->getDirty(), ['updated_at']));

            if ($changes) {
                $client->save();
                $this->audit->log('client.updated', $actor, $client, ['fields' => $changes]);
            }

            return $client;
        });
    }

    public function delete(User $actor, Client $client): void
    {
        if ($client->contracts()->exists()) {
            throw new BusinessRuleException('El cliente tiene contratos registrados. Desactívalo en lugar de eliminarlo para conservar su historial.', 'client_in_use', 409);
        }

        DB::transaction(function () use ($actor, $client) {
            $this->audit->log('client.deleted', $actor, null, ['code' => $client->code, 'name' => $client->name]);
            $client->delete();
        });
    }
}
