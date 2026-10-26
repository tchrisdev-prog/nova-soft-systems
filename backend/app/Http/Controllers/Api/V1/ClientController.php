<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientSector;
use App\Enums\ContractStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Services\Clients\ClientInsights;
use App\Services\Clients\ClientService;
use Illuminate\Support\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function __construct(
        private readonly ClientService $clients,
        private readonly ClientInsights $insights,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Client::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'sector' => ['nullable', Rule::enum(ClientSector::class)],
            'site_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $clients = Client::query()
            ->with(['site', 'contacts'])
            ->withCount(['contracts', 'contracts as active_contracts_count' => fn ($q) => $q->where('status', ContractStatus::Active->value)])
            ->search($validated['search'] ?? null)
            ->when($validated['sector'] ?? null, fn ($q, $sector) => $q->where('sector', $sector))
            ->when($validated['site_id'] ?? null, fn ($q, $site) => $q->where('site_id', $site))
            ->when(($validated['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($validated['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        $this->attachSummary($clients->getCollection());

        return ClientResource::collection($clients);
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $client = $this->clients->create($request->user(), $request->validated());

        return (new ClientResource($this->reload($client)))
            ->additional(['message' => "Cliente {$client->code} registrado."])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Client $client): ClientResource
    {
        Gate::authorize('view', $client);

        return new ClientResource($this->reload($client));
    }

    public function update(UpdateClientRequest $request, Client $client): ClientResource
    {
        $client = $this->clients->update($request->user(), $client, $request->validated());

        return (new ClientResource($this->reload($client)))->additional(['message' => 'Cliente actualizado.']);
    }

    public function destroy(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('delete', $client);
        $this->clients->delete($request->user(), $client);

        return response()->json(['message' => 'Cliente eliminado.']);
    }

    private function reload(Client $client): Client
    {
        $client->load(['site', 'contacts'])->loadCount(['contracts', 'contracts as active_contracts_count' => fn ($q) => $q->where('status', ContractStatus::Active->value)]);
        $this->attachSummary(collect([$client]));

        return $client;
    }

    /** @param  Collection<int, Client>  $clients */
    private function attachSummary(Collection $clients): void
    {
        $summary = $this->insights->for($clients);
        $clients->each(fn (Client $client) => $client->setRelation('summary', $summary[$client->getKey()]));
    }
}
