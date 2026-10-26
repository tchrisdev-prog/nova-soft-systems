<?php

declare(strict_types=1);

namespace App\Services\Support;

use Illuminate\Support\Facades\DB;

/**
 * Genera códigos consecutivos legibles: CLI-001 para clientes y
 * CT-2026-001 para contratos y PRY-2026-001 para proyectos (el consecutivo
 * vuelve a 1 cada año).
 *
 * El siguiente número se toma bloqueando la fila de la serie, así dos
 * personas que crean un contrato al mismo tiempo nunca reciben el mismo
 * código. Debe llamarse dentro de la transacción que guarda el registro.
 */
class CodeGenerator
{
    public function nextClientCode(): string
    {
        return $this->next('client', 'clients', 'CLI-', fn (int $n) => sprintf('CLI-%03d', $n));
    }

    public function nextContractCode(int $year): string
    {
        $prefix = "CT-{$year}-";

        return $this->next("contract-{$year}", 'contracts', $prefix, fn (int $n) => sprintf('%s%03d', $prefix, $n));
    }

    public function nextProjectCode(int $year): string
    {
        $prefix = "PRY-{$year}-";

        return $this->next("project-{$year}", 'projects', $prefix, fn (int $n) => sprintf('%s%03d', $prefix, $n));
    }

    /**
     * @param  callable(int): string  $format
     */
    private function next(string $series, string $table, string $prefix, callable $format): string
    {
        return DB::transaction(function () use ($series, $table, $prefix, $format) {
            // La primera vez se arranca desde el mayor código que ya exista con
            // ese formato, por si alguien registró códigos a mano antes.
            DB::table('document_sequences')->insertOrIgnore([
                'series' => $series,
                'last_value' => $this->highestExisting($table, $prefix),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $current = (int) DB::table('document_sequences')->where('series', $series)->lockForUpdate()->value('last_value');

            // Salta números ocupados por códigos escritos a mano.
            do {
                $current++;
                $code = $format($current);
            } while (DB::table($table)->where('code', $code)->exists());

            DB::table('document_sequences')->where('series', $series)->update(['last_value' => $current, 'updated_at' => now()]);

            return $code;
        });
    }

    private function highestExisting(string $table, string $prefix): int
    {
        return (int) DB::table($table)
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->map(fn (string $code) => preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $code, $m) ? (int) $m[1] : 0)
            ->max();
    }
}
