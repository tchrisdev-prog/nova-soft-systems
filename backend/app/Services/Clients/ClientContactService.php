<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Contactos de un cliente. Siempre hay como máximo un contacto principal:
 * el primero que se registra lo es automáticamente, y si se borra el
 * principal, el contacto más antiguo toma su lugar.
 */
class ClientContactService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data */
    public function add(User $actor, Client $client, array $data): ClientContact
    {
        return DB::transaction(function () use ($actor, $client, $data) {
            $this->lockClient($client);

            $contact = new ClientContact(Arr::only($data, (new ClientContact)->getFillable()));
            $isFirst = ! $client->contacts()->exists();
            $contact->forceFill(['client_id' => $client->getKey(), 'is_primary' => $isFirst || (bool) ($data['is_primary'] ?? false)])->save();

            if ($contact->is_primary) {
                $this->makeOnlyPrimary($client, $contact);
            }

            $this->audit->log('client.contact_added', $actor, $client, ['contact' => $contact->name, 'type' => $contact->type->value]);

            return $contact;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $actor, Client $client, ClientContact $contact, array $data): ClientContact
    {
        return DB::transaction(function () use ($actor, $client, $contact, $data) {
            $this->lockClient($client);
            $contact->fill(Arr::only($data, $contact->getFillable()));

            // Un contacto deja de ser principal solo cuando otro toma su lugar,
            // para que el cliente nunca quede sin a quién escribirle.
            if (($data['is_primary'] ?? false) === true) {
                $contact->forceFill(['is_primary' => true]);
            }

            $changed = array_keys(Arr::except($contact->getDirty(), ['updated_at']));
            $contact->save();

            if ($contact->is_primary) {
                $this->makeOnlyPrimary($client, $contact);
            }

            if ($changed) {
                $this->audit->log('client.contact_updated', $actor, $client, ['contact' => $contact->name, 'fields' => $changed]);
            }

            return $contact;
        });
    }

    public function remove(User $actor, Client $client, ClientContact $contact): void
    {
        DB::transaction(function () use ($actor, $client, $contact) {
            $this->lockClient($client);
            $wasPrimary = $contact->is_primary;
            $contact->delete();

            if ($wasPrimary) {
                $next = ClientContact::query()->where('client_id', $client->getKey())->orderBy('id')->first();
                $next?->forceFill(['is_primary' => true])->save();
            }

            $this->audit->log('client.contact_removed', $actor, $client, ['contact' => $contact->name]);
        });
    }

    /**
     * Crea los contactos que llegan junto con un cliente nuevo.
     *
     * @param  list<array<string, mixed>>  $contacts
     */
    public function addMany(User $actor, Client $client, array $contacts): void
    {
        foreach ($contacts as $data) {
            $this->add($actor, $client, $data);
        }
    }

    private function makeOnlyPrimary(Client $client, ClientContact $primary): void
    {
        ClientContact::query()
            ->where('client_id', $client->getKey())
            ->whereKeyNot($primary->getKey())
            ->where('is_primary', true)
            ->update(['is_primary' => false, 'updated_at' => now()]);
    }

    /** Serializa los cambios de contactos del mismo cliente. */
    private function lockClient(Client $client): void
    {
        Client::query()->whereKey($client->getKey())->lockForUpdate()->first();
    }
}
