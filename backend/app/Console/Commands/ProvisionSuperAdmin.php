<?php

namespace App\Console\Commands;

use App\Services\Users\SuperAdminProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class ProvisionSuperAdmin extends Command
{
    protected $signature = 'nova:super-admin
                            {email : Correo corporativo (UPN) de la cuenta de Microsoft 365}
                            {--name= : Nombre visible (opcional; se actualiza en el primer ingreso)}
                            {--force : No solicitar confirmación}';

    protected $description = 'Autoriza una cuenta corporativa y le asigna el rol de sistema Super Admin';

    public function handle(SuperAdminProvisioner $provisioner): int
    {
        $email = (string) $this->argument('email');
        $domains = config('nova.auth.allowed_email_domains', []);

        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:190']]);

        if ($validator->fails()) {
            $this->error('El correo indicado no es válido.');

            return self::INVALID;
        }

        $domain = mb_strtolower((string) substr(strrchr($email, '@'), 1));

        if ($domains && ! in_array($domain, $domains, true)) {
            $this->error("El dominio [{$domain}] no está permitido (NOVA_ALLOWED_EMAIL_DOMAINS).");

            return self::INVALID;
        }

        $this->warn('El rol Super Admin otorga acceso total a la administración del ERP.');

        if (! $this->option('force') && ! $this->confirm("¿Asignar el rol Super Admin a {$email}?")) {
            $this->line('Operación cancelada.');

            return self::FAILURE;
        }

        $result = $provisioner->provision($email, $this->option('name'));

        if ($result['already_super_admin']) {
            $this->info("{$email} ya tenía el rol Super Admin. La cuenta quedó activa.");
        } else {
            $this->info(($result['created'] ? 'Cuenta autorizada' : 'Cuenta existente').": {$email} ahora es Super Admin.");
        }

        $this->line('El acceso se realiza exclusivamente con el inicio de sesión de Microsoft 365 de esa cuenta.');

        return self::SUCCESS;
    }
}
