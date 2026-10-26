<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Contracts\ContractPeriods;
use Illuminate\Console\Command;

class RenewContractPeriods extends Command
{
    protected $signature = 'nova:contracts-renew';

    protected $description = 'Abre el mes en curso de las mensualidades activas (se puede ejecutar varias veces sin duplicar)';

    public function handle(ContractPeriods $periods): int
    {
        $opened = $periods->renewAll();
        $this->info($opened === 0 ? 'No había meses por abrir.' : "Meses abiertos: {$opened}.");

        return self::SUCCESS;
    }
}
