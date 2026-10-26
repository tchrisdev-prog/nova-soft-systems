<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Enums\ContractStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Services\Contracts\ContractInsights;
use Illuminate\Support\Collection;

/**
 * Resumen comercial de cada cliente: cuánto factura al mes en mensualidades,
 * cuántas horas le quedan en sus bolsas y cuántos contratos piden atención.
 * Se arma con los contratos vigentes de la página completa de una vez.
 */
class ClientInsights
{
    public function __construct(private readonly ContractInsights $contracts) {}

    /**
     * @param  Collection<int, Client>  $clients
     * @return array<int, array<string, int>>
     */
    public function for(Collection $clients): array
    {
        $contracts = Contract::query()
            ->whereIn('client_id', $clients->map(fn ($client) => $client->getKey())->all())
            ->whereIn('status', [ContractStatus::Active->value, ContractStatus::Suspended->value])
            ->get();

        $insights = $this->contracts->for($contracts);
        $summary = [];

        foreach ($clients as $client) {
            $summary[$client->getKey()] = [
                'active_contracts' => 0,
                'monthly_recurring' => 0,
                'hour_bags' => 0,
                'bag_balance_minutes' => 0,
                'alerts' => 0,
            ];
        }

        foreach ($contracts as $contract) {
            $row = &$summary[$contract->client_id];
            $info = $insights[$contract->getKey()];

            if ($contract->status === ContractStatus::Active) {
                $row['active_contracts']++;

                if ($info['consumption']['type'] === 'monthly_recurring') {
                    $row['monthly_recurring'] += (int) $info['consumption']['fee_amount'];
                } else {
                    $row['hour_bags']++;
                    $row['bag_balance_minutes'] += $info['consumption']['balance_minutes'];
                }
            }

            $row['alerts'] += count($info['alerts']);
            unset($row);
        }

        return $summary;
    }
}
