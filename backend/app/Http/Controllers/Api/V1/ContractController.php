<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\BillingModel;
use App\Enums\ContractStatus;
use App\Enums\Coverage;
use App\Enums\ServiceLine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\StoreContractRequest;
use App\Http\Requests\Contracts\StoreHourMovementRequest;
use App\Http\Requests\Contracts\UpdateContractRequest;
use App\Http\Requests\Contracts\UpdateContractStatusRequest;
use App\Http\Resources\ContractResource;
use App\Http\Resources\ContractRevisionResource;
use App\Http\Resources\HourMovementResource;
use App\Models\Client;
use App\Models\Contract;
use App\Services\Contracts\ContractConsumption;
use App\Services\Contracts\ContractInsights;
use App\Services\Contracts\ContractsExport;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\Contracts\ContractService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ContractController extends Controller
{
    public function __construct(
        private readonly ContractService $contracts,
        private readonly ContractConsumption $consumption,
        private readonly ContractInsights $insights,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Contract::class);
        $validated = $this->validateFilters($request);

        $contracts = $this->filtered($validated)
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        $this->attachInsights($contracts->getCollection());

        return ContractResource::collection($contracts);
    }

    /**
     * Exporta los contratos filtrados a un archivo que Excel abre directamente
     * (separado por punto y coma, como lo espera Excel en español). Exportar
     * datos comerciales queda registrado en la auditoría.
     */
    public function export(Request $request, ContractsExport $export): StreamedResponse
    {
        Gate::authorize('viewAny', Contract::class);
        $validated = $this->validateFilters($request);

        return $export->stream($request->user(), $this->filtered($validated), array_filter($validated));
    }

    /** @return array<string, mixed> */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'client_id' => ['nullable', 'integer'],
            'service_line' => ['nullable', Rule::enum(ServiceLine::class)],
            'billing_model' => ['nullable', Rule::enum(BillingModel::class)],
            'coverage' => ['nullable', Rule::enum(Coverage::class)],
            'status' => ['nullable', Rule::enum(ContractStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    /** @param  array<string, mixed>  $validated */
    private function filtered(array $validated): Builder
    {
        return $this->withBagTotals(Contract::query())
            ->with('client')
            ->withCount('projects')
            ->search($validated['search'] ?? null)
            ->when($validated['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($validated['service_line'] ?? null, fn ($q, $v) => $q->where('service_line', $v))
            ->when($validated['billing_model'] ?? null, fn ($q, $v) => $q->where('billing_model', $v))
            ->when($validated['coverage'] ?? null, fn ($q, $v) => $q->where('coverage', $v))
            ->when($validated['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('start_date')
            ->orderBy('code');
    }

    /** @param  Collection<int, Contract>  $contracts */
    private function attachInsights(Collection $contracts): void
    {
        $insights = $this->insights->for($contracts);
        $contracts->each(fn (Contract $contract) => $contract->setRelation('insights', $insights[$contract->getKey()]));
    }

    public function store(StoreContractRequest $request): JsonResponse
    {
        $client = Client::query()->findOrFail($request->validated('client_id'));
        $contract = $this->contracts->create($request->user(), $client, $request->validated());

        return (new ContractResource($this->reload($contract)))
            ->additional(['message' => "Contrato {$contract->code} registrado en borrador."])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Contract $contract): ContractResource
    {
        Gate::authorize('view', $contract);

        return new ContractResource($this->reload($contract));
    }

    public function update(UpdateContractRequest $request, Contract $contract): ContractResource
    {
        $contract = $this->contracts->update($request->user(), $contract, $request->contractData(), $request->validated('note'));

        return (new ContractResource($this->reload($contract)))->additional(['message' => 'Contrato actualizado.']);
    }

    public function updateStatus(UpdateContractStatusRequest $request, Contract $contract): ContractResource
    {
        $contract = $this->contracts->changeStatus($request->user(), $contract, $request->status(), $request->validated('note'));

        return (new ContractResource($this->reload($contract)))
            ->additional(['message' => "Contrato {$contract->status->label()}."]);
    }

    public function destroy(Request $request, Contract $contract): JsonResponse
    {
        Gate::authorize('delete', $contract);
        $this->contracts->delete($request->user(), $contract);

        return response()->json(['message' => 'Contrato eliminado.']);
    }

    /** Historial de cambios, del más reciente al más antiguo. */
    public function history(Request $request, Contract $contract): AnonymousResourceCollection
    {
        Gate::authorize('view', $contract);
        $perPage = (int) ($request->validate(['per_page' => ['nullable', 'integer', 'min:5', 'max:100']])['per_page'] ?? 20);

        return ContractRevisionResource::collection(
            $contract->revisions()->with('actor')->orderByDesc('id')->paginate($perPage)->withQueryString()
        );
    }

    public function movements(Contract $contract): AnonymousResourceCollection
    {
        Gate::authorize('view', $contract);

        return HourMovementResource::collection($contract->hourMovements()->with('author')->orderBy('reference_date')->orderBy('id')->get());
    }

    public function addHours(StoreHourMovementRequest $request, Contract $contract): JsonResponse
    {
        $movement = $this->contracts->addHours(
            $request->user(),
            $contract,
            $request->movementType(),
            (int) $request->validated('minutes'),
            (int) $request->validated('amount'),
            $request->validated('reference_date'),
            $request->validated('note'),
        );

        return (new HourMovementResource($movement->load('author')))
            ->additional(['message' => 'Movimiento registrado en la bolsa de horas.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Consumo del contrato. Para quien no tiene projects.financials se quitan
     * el costo y el margen de la respuesta, en todos sus niveles.
     */
    public function consumption(Request $request, Contract $contract): JsonResponse
    {
        Gate::authorize('view', $contract);
        $months = (int) ($request->validate(['months' => ['nullable', 'integer', 'min:1', 'max:24']])['months'] ?? 6);

        $summary = $this->consumption->summarize($contract, $months);

        if (! $request->user()->can('viewFinancials', $contract)) {
            $summary = self::withoutFinancials($summary);
        }

        return response()->json(['data' => $summary]);
    }

    /** @param  array<string, mixed>  $data */
    private static function withoutFinancials(array $data): array
    {
        unset($data['cost'], $data['margin']);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::withoutFinancials($value);
            }
        }

        return $data;
    }

    private function reload(Contract $contract): Contract
    {
        $fresh = $this->withBagTotals(Contract::query())->with('client')->withCount('projects')->findOrFail($contract->getKey());
        $this->attachInsights(collect([$fresh]));

        return $fresh;
    }

    private function withBagTotals(Builder $query): Builder
    {
        return $query
            ->withSum('hourMovements as purchased_minutes', 'minutes')
            ->withSum('hourMovements as contract_value', 'amount');
    }
}
