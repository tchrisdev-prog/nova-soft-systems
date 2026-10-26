<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Audit\AuditLogger;
use App\Services\Authorization\PermissionCatalog;
use App\Services\Projects\ModuleSetup;
use Illuminate\Console\Command;

class SetupProjectsModule extends Command
{
    protected $signature = 'nova:setup-projects
                            {--reset-permissions : Restablece los permisos de las plantillas de roles aunque ya existan}';

    protected $description = 'Crea las sedes y las plantillas de roles del módulo de Proyectos y Capacidad';

    public function handle(PermissionCatalog $catalog, ModuleSetup $setup, AuditLogger $audit): int
    {
        // Primero el catálogo: las plantillas necesitan que los permisos existan.
        $catalog->sync();
        $result = $setup->run((bool) $this->option('reset-permissions'));

        $this->info('Sedes: '.implode(', ', $result['sites']).'.');
        $this->info($result['created_roles'] ? 'Roles creados: '.implode(', ', $result['created_roles']).'.' : 'Las plantillas de roles ya existían; no se modificaron.');

        if ($result['reset_roles']) {
            $this->warn('Permisos restablecidos en: '.implode(', ', $result['reset_roles']).'.');
        }

        $audit->log('projects.setup', null, null, $result);

        return self::SUCCESS;
    }
}
