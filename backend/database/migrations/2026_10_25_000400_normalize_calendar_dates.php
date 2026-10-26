<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deja las fechas de calendario guardadas antes como "AAAA-MM-DD" (ver
 * App\Casts\CalendarDate). Solo hace falta en SQLite, donde las fechas son
 * texto; en PostgreSQL las columnas ya son de tipo fecha.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'contracts' => ['start_date', 'end_date'],
        'contract_hour_movements' => ['reference_date'],
        'contract_periods' => ['period_start', 'period_end'],
        'projects' => ['start_date', 'end_date'],
        'allocations' => ['start_date', 'end_date'],
        'time_entries' => ['work_date'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)->whereNotNull($column)->update([$column => DB::raw("substr({$column}, 1, 10)")]);
            }
        }
    }

    public function down(): void
    {
        // No hay nada que deshacer: "AAAA-MM-DD" también es una fecha válida.
    }
};
