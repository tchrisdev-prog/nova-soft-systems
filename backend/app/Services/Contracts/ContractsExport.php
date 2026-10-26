<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\BusinessCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación de contratos para Excel.
 *
 * - Separador punto y coma y BOM UTF-8: así Excel en español abre el archivo
 *   con las columnas y las tildes bien, sin pasos extra.
 * - Las celdas que empiezan con = + - @ se neutralizan para que nadie pueda
 *   colar una fórmula en un nombre (la llamada "inyección CSV").
 * - Ingresos, costos y margen solo salen para quien tiene projects.financials.
 * - Se escribe por bloques, sin cargar todos los contratos en memoria.
 */
class ContractsExport
{
    public const MAX_ROWS = 5000;

    public function __construct(
        private readonly ContractInsights $insights,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function stream(User $actor, Builder $query, array $filters): StreamedResponse
    {
        $financials = $actor->hasPermission('projects.financials');
        $total = min((clone $query)->count(), self::MAX_ROWS);

        $this->audit->log('contracts.exported', $actor, null, [
            'rows' => $total,
            'filters' => $filters,
            'with_financials' => $financials,
        ]);

        $filename = 'contratos-'.BusinessCalendar::today()->format('Y-m-d').'.csv';

        return new StreamedResponse(function () use ($query, $financials) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $this->write($out, $this->header($financials));

            $written = 0;
            $query->limit(self::MAX_ROWS)->chunk(200, function (Collection $contracts) use ($out, $financials, &$written) {
                $insights = $this->insights->for($contracts);

                foreach ($contracts as $contract) {
                    if ($written++ >= self::MAX_ROWS) {
                        return false;
                    }

                    $this->write($out, $this->row($contract, $insights[$contract->getKey()], $financials));
                }
            });

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return list<string> */
    private function header(bool $financials): array
    {
        $columns = [
            'Código', 'Cliente', 'Contrato', 'Línea de servicio', 'Forma de cobro', 'Cobertura', 'Estado',
            'Inicio', 'Fin', 'Valor mensual (COP)', 'Valor de la bolsa (COP)', 'Horas compradas o incluidas',
            'Horas aprobadas', 'Horas pendientes', 'Saldo de horas', '% de consumo', 'Alertas',
        ];

        return $financials ? [...$columns, 'Ingreso a la fecha (COP)', 'Costo interno (COP)', 'Margen (COP)'] : $columns;
    }

    /**
     * @param  array<string, mixed>  $info
     * @return list<string|int|null>
     */
    private function row(Contract $contract, array $info, bool $financials): array
    {
        $c = $info['consumption'];
        $isBag = $c['type'] === 'hour_bag';

        $row = [
            $contract->code,
            $contract->client?->name,
            $contract->name,
            $contract->service_line->label(),
            $contract->billing_model->label(),
            $contract->coverage->label(),
            $contract->status->label(),
            $contract->start_date?->toDateString(),
            $contract->end_date?->toDateString(),
            $isBag ? null : $c['fee_amount'],
            $isBag ? $info['financials']['revenue_to_date'] : null,
            self::hours($isBag ? $c['purchased_minutes'] : $c['included_minutes']),
            self::hours($c['approved_minutes']),
            self::hours($c['pending_minutes']),
            $isBag ? self::hours($c['balance_minutes']) : null,
            $c['consumed_ratio'] !== null ? number_format($c['consumed_ratio'] * 100, 1, ',', '') : null,
            implode(' | ', array_column($info['alerts'], 'message')),
        ];

        if ($financials) {
            $row[] = $info['financials']['revenue_to_date'];
            $row[] = $info['financials']['approved_cost'];
            $row[] = $info['financials']['margin'];
        }

        return $row;
    }

    /** @param  list<string|int|null>  $values */
    private function write($handle, array $values): void
    {
        fputcsv($handle, array_map([self::class, 'safeCell'], $values), ';', '"', '');
    }

    /**
     * Excel ejecuta como fórmula lo que empieza con = + - @ (o tabulador).
     * Un nombre de contrato como "=HIPERVINCULO(...)" se exporta como texto.
     */
    public static function safeCell(string|int|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private static function hours(?int $minutes): ?string
    {
        return $minutes === null ? null : number_format($minutes / 60, 2, ',', '');
    }
}
