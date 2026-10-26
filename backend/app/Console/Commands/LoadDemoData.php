<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Demo\DemoDataBuilder;
use Illuminate\Console\Command;
use Throwable;

class LoadDemoData extends Command
{
    protected $signature = 'nova:demo
                            {--reset : Borra los datos de demostración existentes y los vuelve a generar}
                            {--remove : Solo borra los datos de demostración}
                            {--presenter= : Correo de una cuenta ya autorizada que quedará como líder de dos proyectos}
                            {--with-linked : Al borrar, incluye también contratos y proyectos creados en ensayos sobre datos de demostración}
                            {--force : No pedir confirmación}';

    protected $description = 'Carga datos de demostración de Nexus Safe-Cloud (nunca en producción)';

    public function handle(DemoDataBuilder $demo): int
    {
        // Primera barrera: el comando ni siquiera arranca en producción. El
        // constructor de datos vuelve a revisarlo por si alguien lo llama
        // desde otro lugar.
        if ($this->laravel->isProduction()) {
            $this->error('Los datos de demostración nunca se cargan en producción.');

            return self::FAILURE;
        }

        $environment = $this->laravel->environment();

        if (! $this->option('force') && ! in_array($environment, ['local', 'testing'], true)
            && ! $this->confirm("Vas a cargar datos de demostración en el ambiente «{$environment}». ¿Continuar?")) {
            return self::FAILURE;
        }

        $presenter = null;

        if ($email = $this->option('presenter')) {
            $presenter = User::query()->where('email', mb_strtolower(trim((string) $email)))->where('is_active', true)->first();

            if (! $presenter) {
                $this->error("No hay una cuenta activa con el correo {$email}. Autorízala primero (por ejemplo, con nova:super-admin).");

                return self::FAILURE;
            }
        }

        try {
            if ($this->option('reset') || $this->option('remove')) {
                $removed = $demo->reset((bool) $this->option('with-linked'));
                $this->info("Datos de demostración borrados: {$removed['users']} personas, {$removed['clients']} clientes, {$removed['contracts']} contratos, {$removed['projects']} proyectos, {$removed['entries']} registros de horas.");

                $linked = array_merge($removed['linked']['contracts'], $removed['linked']['projects']);
                if ($linked !== []) {
                    $this->warn('También se borraron, por depender de la demostración: '.implode(', ', $linked).'.');
                }

                if ($this->option('remove')) {
                    return self::SUCCESS;
                }
            }

            $this->info('Generando datos de demostración…');
            $summary = $demo->build($presenter);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<options=bold>Nexus Safe-Cloud (datos de demostración)</>');
        $this->table(['Dato', 'Cantidad'], [
            ['Personas', $summary['people'].' ('.collect($summary['by_site'])->map(fn ($n, $site) => ($site === 'bogota' ? 'Bogotá' : 'Medellín').": {$n}")->implode(', ').')'],
            ['Clientes', $summary['clients']],
            ['Contratos', $summary['contracts']],
            ['Proyectos', $summary['projects']],
            ['Asignaciones', $summary['allocations']],
            ['Horas aprobadas / enviadas / borrador / rechazadas', implode(' / ', [
                $summary['entries']['approved'], $summary['entries']['submitted'], $summary['entries']['draft'], $summary['entries']['rejected'],
            ]).' registros'],
        ]);

        $this->line('<options=bold>Casos para la presentación</>');
        $this->table(['Caso', 'Dónde verlo'], collect($summary['cases'])->map(fn ($value, $case) => [$case, $value])->values()->all());

        $this->comment('Las cuentas de demostración usan el dominio @'.DemoDataBuilder::EMAIL_DOMAIN.' y no pueden iniciar sesión.');

        return self::SUCCESS;
    }
}
