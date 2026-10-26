<?php

namespace App\Console\Commands;

use App\Services\Authorization\PermissionCatalog;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    protected $signature = 'nova:sync-permissions
                            {--prune : Elimina módulos y permisos que ya no existen en config/nova.php}';

    protected $description = 'Sincroniza módulos y permisos del ERP desde config/nova.php';

    public function handle(PermissionCatalog $catalog): int
    {
        $result = $catalog->sync((bool) $this->option('prune'));

        $this->info("Módulos sincronizados: {$result['modules']}. Permisos: {$result['permissions']}.");

        $verb = $this->option('prune') ? 'Eliminados' : 'Fuera del catálogo (usa --prune para eliminarlos)';

        if ($result['removed_modules']) {
            $this->warn("Módulos {$verb}: ".implode(', ', $result['removed_modules']));
        }

        if ($result['removed_permissions']) {
            $this->warn("Permisos {$verb}: ".implode(', ', $result['removed_permissions']));
        }

        return self::SUCCESS;
    }
}
