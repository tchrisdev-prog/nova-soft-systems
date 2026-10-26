<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\ContactRequest;
use App\Http\Resources\ClientContactResource;
use App\Models\Client;
use App\Models\ClientContact;
use App\Services\Clients\ClientContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Contactos de un cliente. Las rutas usan "scoped bindings": un contacto solo
 * se encuentra dentro de su propio cliente, así no se puede editar el
 * contacto de otro cliente cambiando el número en la URL.
 */
class ClientContactController extends Controller
{
    public function __construct(private readonly ClientContactService $contacts) {}

    public function index(Client $client): AnonymousResourceCollection
    {
        Gate::authorize('view', $client);

        return ClientContactResource::collection($client->contacts()->get());
    }

    public function store(ContactRequest $request, Client $client): JsonResponse
    {
        $contact = $this->contacts->add($request->user(), $client, $request->validated());

        return (new ClientContactResource($contact))
            ->additional(['message' => 'Contacto agregado.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(ContactRequest $request, Client $client, ClientContact $contact): ClientContactResource
    {
        $contact = $this->contacts->update($request->user(), $client, $contact, $request->validated());

        return (new ClientContactResource($contact))->additional(['message' => 'Contacto actualizado.']);
    }

    public function destroy(Request $request, Client $client, ClientContact $contact): JsonResponse
    {
        Gate::authorize('update', $client);
        $this->contacts->remove($request->user(), $client, $contact);

        return response()->json(['message' => 'Contacto eliminado.']);
    }
}
