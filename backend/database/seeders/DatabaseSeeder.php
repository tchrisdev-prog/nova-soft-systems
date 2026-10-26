<?php

namespace Database\Seeders;

use App\Services\Authorization\PermissionCatalog;
use Illuminate\Database\Seeder;

/**
 * Solo registra el catálogo de módulos/permisos y el rol de sistema.
 * No crea usuarios ni credenciales: el Super Admin se aprovisiona con
 * php artisan nova:super-admin <correo>.
 */
class DatabaseSeeder extends Seeder
{
    public function run(PermissionCatalog $catalog): void
    {
        $catalog->sync();
    }
}
