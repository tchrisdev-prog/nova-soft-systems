<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Enums\ClientSector;
use App\Enums\ContactType;
use App\Enums\ContractStatus;
use App\Enums\HourMovementType;
use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Enums\TimeEntryStatus;
use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffProfile;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Authorization\PermissionCatalog;
use App\Services\Contracts\ContractPeriods;
use App\Services\Contracts\ContractService;
use App\Services\Projects\AllocationService;
use App\Services\Projects\ModuleSetup;
use App\Support\BusinessCalendar;
use App\Support\ColombianHolidays;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Datos de demostración de Nexus Safe-Cloud para la presentación.
 *
 * Todo es ficticio: personas, clientes y valores. Se reconoce por el dominio
 * de correo demo.nexus.test y por el prefijo DEMO- en los códigos, y así se
 * puede borrar sin tocar nada real.
 *
 * Las fechas se calculan desde hoy hacia atrás (cada contrato tiene horas
 * desde su inicio, hasta unos ocho meses), para que la demo siempre se vea actual. Con la misma semilla genera siempre
 * los mismos datos, lo que ayuda a ensayar la presentación.
 *
 * Los contratos se crean con los mismos servicios que usa la API, "viajando"
 * en el tiempo a la fecha de cada evento: así el historial de cada contrato
 * queda con fechas creíbles y pasa por las mismas reglas que en producción.
 * Las horas, en cambio, se insertan en bloque: son miles de registros con
 * fechas pasadas que la API (con razón) no permitiría registrar hoy.
 */
final class DemoDataBuilder
{
    public const EMAIL_DOMAIN = 'demo.nexus.test';

    public const CODE_PREFIX = 'DEMO-';

    private const SEED = 2026;

    private Randomizer $random;

    private CarbonImmutable $today;

    /** @var array<string, User> */
    private array $people = [];

    /** @var array<string, array{position: string, cost: int, pool: string, site: string}> */
    private array $profiles = [];

    /** @var array<string, Client> */
    private array $clients = [];

    /** @var array<string, Contract> */
    private array $contracts = [];

    /** @var array<string, Project> */
    private array $projects = [];

    /** Reloj de prueba que había antes de empezar (normalmente ninguno). */
    private ?CarbonInterface $originalNow = null;

    /** @var array<string, int> carga asignada actual por persona (en %) */
    private array $load = [];

    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly ModuleSetup $setup,
        private readonly ContractService $contractService,
        private readonly ContractPeriods $periods,
        private readonly AllocationService $allocationService,
    ) {}

    public function exists(): bool
    {
        return User::withTrashed()->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->exists()
            || Client::query()->where('code', 'like', self::CODE_PREFIX.'%')->exists();
    }

    /**
     * Borra solo lo marcado como demostración, en el orden que exigen las
     * llaves foráneas.
     *
     * @return array<string, int>
     */
    /**
     * Registros reales (sin el prefijo DEMO-) colgados de datos de
     * demostración: un contrato registrado en un cliente ficticio o un
     * proyecto sobre un contrato ficticio, típico de los ensayos en el panel.
     * Impiden borrar la demo, porque la base de datos protege esas relaciones.
     *
     * @return array{contracts: list<string>, projects: list<string>}
     */
    public function linkedRecords(): array
    {
        $demoClients = Client::query()->where('code', 'like', self::CODE_PREFIX.'%')->pluck('id');
        $contracts = Contract::query()
            ->whereIn('client_id', $demoClients)
            ->where('code', 'not like', self::CODE_PREFIX.'%')
            ->orderBy('code');

        $projects = Project::query()
            ->where('code', 'not like', self::CODE_PREFIX.'%')
            ->where(fn ($q) => $q
                ->whereIn('contract_id', Contract::query()->where('code', 'like', self::CODE_PREFIX.'%')->select('id'))
                ->orWhereIn('contract_id', (clone $contracts)->select('id')))
            ->orderBy('code');

        return [
            'contracts' => $contracts->pluck('code')->all(),
            'projects' => $projects->pluck('code')->all(),
        ];
    }

    /**
     * Borra la demo. Si hay registros reales colgados de ella, se detiene y
     * dice cuáles son; con $withLinked los borra también (se informa cuáles).
     *
     * @return array{users: int, clients: int, contracts: int, projects: int, entries: int, linked: array{contracts: list<string>, projects: list<string>}}
     */
    public function reset(bool $withLinked = false): array
    {
        self::assertNotProduction();
        $linked = $this->linkedRecords();

        if (! $withLinked && ($linked['contracts'] || $linked['projects'])) {
            $list = implode(', ', array_merge($linked['contracts'], $linked['projects']));

            throw new RuntimeException(
                "No se borró nada: hay registros que no son de la demostración pero dependen de ella ({$list}). "
                .'Probablemente se crearon en un ensayo sobre un cliente o contrato de demostración. '
                .'Si también quieres borrarlos, repite el comando con --with-linked.'
            );
        }

        return DB::transaction(function () use ($linked) {
            $userIds = User::withTrashed()->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->pluck('id');
            $projectIds = Project::query()->where('code', 'like', self::CODE_PREFIX.'%')
                ->orWhereIn('code', $linked['projects'])
                ->pluck('id');
            $contractIds = Contract::query()->where('code', 'like', self::CODE_PREFIX.'%')
                ->orWhereIn('code', $linked['contracts'])
                ->pluck('id');
            // Proyectos reales sobre los contratos vinculados (que también se van).
            $projectIds = $projectIds->merge(Project::query()->whereIn('contract_id', $contractIds)->pluck('id'))->unique()->values();

            $entries = TimeEntry::query()->whereIn('project_id', $projectIds)->orWhereIn('user_id', $userIds)->delete();
            Allocation::query()->whereIn('project_id', $projectIds)->orWhereIn('user_id', $userIds)->delete();
            $projects = Project::query()->whereKey($projectIds)->delete();

            // El historial y los movimientos son inmutables para la aplicación;
            // aquí se borran con la consulta directa porque se elimina el
            // contrato de demostración completo.
            DB::table('contract_revisions')->whereIn('contract_id', $contractIds)->delete();
            DB::table('contract_hour_movements')->whereIn('contract_id', $contractIds)->delete();
            DB::table('contract_periods')->whereIn('contract_id', $contractIds)->delete();
            $contracts = Contract::query()->whereKey($contractIds)->delete();
            $clients = Client::query()->where('code', 'like', self::CODE_PREFIX.'%')->delete();

            // Lo que "hicieron" personas ficticias tampoco es historia real.
            AuditLog::query()->whereIn('actor_id', $userIds)->delete();
            // Las cuentas de demostración se borran de verdad: no son historia real.
            $users = User::withTrashed()->whereKey($userIds)->forceDelete();

            return compact('users', 'clients', 'contracts', 'projects', 'entries', 'linked');
        });
    }

    /**
     * @return array<string, mixed> resumen para mostrar en consola
     */
    public function build(?User $presenter = null): array
    {
        self::assertNotProduction();

        if ($this->exists()) {
            throw new RuntimeException('Ya hay datos de demostración. Usa --reset para regenerarlos.');
        }

        $this->random = new Randomizer(new Mt19937(self::SEED));
        $this->today = BusinessCalendar::today();
        $testNow = CarbonImmutable::getTestNow();
        $this->originalNow = $testNow instanceof CarbonInterface ? $testNow : null;

        $this->catalog->sync();
        $this->setup->run();

        try {
            return DB::transaction(function () use ($presenter) {
                $this->createPeople();
                $this->createClients();

                // Primero se planean equipos y horas (con claves, sin ids): así
                // se sabe cuántas horas tendrá cada bolsa antes de crearla.
                $plan = $this->planWork();
                $rows = $this->plannedEntries($plan);

                $this->createContractsAndProjects($plan, $rows);
                $overallocated = $this->createAllocations($plan);
                $entries = $this->insertTimeEntries($rows);

                if ($presenter) {
                    $this->attachPresenter($presenter);
                }

                return $this->summary($entries, $overallocated, $presenter);
            });
        } finally {
            $this->travel($this->originalNow);
        }
    }

    public static function assertNotProduction(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Los datos de demostración nunca se cargan en producción.');
        }
    }

    // ------------------------------------------------------------------
    // Personas
    // ------------------------------------------------------------------

    /**
     * 47 personas: 37 en Medellín y 10 en Bogotá.
     * [nombre, género (para el cargo), sede, rol, tipo de cargo]
     */
    private const PEOPLE = [
        ['Mariana Restrepo Vélez', 'f', 'medellin', 'gerencia', 'ceo'],
        ['Ricardo Martínez Pardo', 'm', 'bogota', 'subgerencia', 'coo'],
        ['Santiago Ochoa Arango', 'm', 'medellin', 'comercial', 'sales'],
        ['Valentina Zapata Gómez', 'f', 'medellin', 'comercial', 'sales'],
        ['Diana Carolina Rojas Peña', 'f', 'bogota', 'comercial', 'sales'],
        ['Andrés Felipe Cardona Ríos', 'm', 'medellin', 'lider-de-proyecto', 'leader'],
        ['Laura Marcela Henao Muñoz', 'f', 'medellin', 'lider-de-proyecto', 'leader'],
        ['Julián Escobar Mesa', 'm', 'medellin', 'lider-de-proyecto', 'leader'],
        ['Catalina Giraldo Uribe', 'f', 'medellin', 'lider-de-proyecto', 'leader'],
        ['Fernando Castillo Herrera', 'm', 'bogota', 'lider-de-proyecto', 'leader'],
        ['Paola Andrea Suárez Niño', 'f', 'bogota', 'lider-de-proyecto', 'leader'],
        // Consultores en Medellín (30)
        ['Camilo Arango Posada', 'm', 'medellin', 'consultor', 'fullstack'],
        ['Daniela Montoya Restrepo', 'f', 'medellin', 'consultor', 'fullstack'],
        ['Sebastián Gil Correa', 'm', 'medellin', 'consultor', 'fullstack'],
        ['Manuela Ospina Jaramillo', 'f', 'medellin', 'consultor', 'fullstack'],
        ['Juan Pablo Vélez Toro', 'm', 'medellin', 'consultor', 'fullstack'],
        ['Sara Isabel Mejía Betancur', 'f', 'medellin', 'consultor', 'fullstack'],
        ['Mateo Quintero Álvarez', 'm', 'medellin', 'consultor', 'fullstack'],
        ['Isabella Duque Salazar', 'f', 'medellin', 'consultor', 'fullstack'],
        ['Esteban Ramírez Londoño', 'm', 'medellin', 'consultor', 'fullstack'],
        ['Luisa Fernanda Castaño Ruiz', 'f', 'medellin', 'consultor', 'fullstack'],
        ['Alejandro Hoyos Cadavid', 'm', 'medellin', 'consultor', 'backend'],
        ['Paula Andrea Bedoya Marín', 'f', 'medellin', 'consultor', 'backend'],
        ['Simón Agudelo Franco', 'm', 'medellin', 'consultor', 'backend'],
        ['Juliana Echeverri Rendón', 'f', 'medellin', 'consultor', 'backend'],
        ['David Alzate Montoya', 'm', 'medellin', 'consultor', 'backend'],
        ['Natalia Sierra Vásquez', 'f', 'medellin', 'consultor', 'qa'],
        ['Tomás Palacio Arboleda', 'm', 'medellin', 'consultor', 'qa'],
        ['María José Tobón Pérez', 'f', 'medellin', 'consultor', 'qa'],
        ['Nicolás Arcila Bermúdez', 'm', 'medellin', 'consultor', 'infra'],
        ['Antonia Lopera Gaviria', 'f', 'medellin', 'consultor', 'infra'],
        ['Felipe Carmona Osorio', 'm', 'medellin', 'consultor', 'infra'],
        ['Verónica Pineda Valencia', 'f', 'medellin', 'consultor', 'infra'],
        ['Samuel Patiño Cano', 'm', 'medellin', 'consultor', 'cloud'],
        ['Carolina Vargas Lozano', 'f', 'medellin', 'consultor', 'cloud'],
        ['Miguel Ángel Rúa Zuluaga', 'm', 'medellin', 'consultor', 'cloud'],
        ['Melissa Arias Hincapié', 'f', 'medellin', 'consultor', 'cyber'],
        ['Daniel Builes Rincón', 'm', 'medellin', 'consultor', 'cyber'],
        ['Ana Sofía Granda Molina', 'f', 'medellin', 'consultor', 'cyber'],
        ['Cristian Taborda Giraldo', 'm', 'medellin', 'consultor', 'soc'],
        ['Alejandra Úsuga Salazar', 'f', 'medellin', 'consultor', 'soc'],
        // Consultores en Bogotá (6)
        ['Óscar Iván Moreno Silva', 'm', 'bogota', 'consultor', 'infra'],
        ['Lina María Torres Cárdenas', 'f', 'bogota', 'consultor', 'infra'],
        ['Jorge Eduardo Bernal Ruiz', 'm', 'bogota', 'consultor', 'cloud'],
        ['Angélica Romero Vargas', 'f', 'bogota', 'consultor', 'cloud'],
        ['Kevin Andrés Rincón Díaz', 'm', 'bogota', 'consultor', 'cyber'],
        ['Tatiana Forero Acosta', 'f', 'bogota', 'consultor', 'cyber'],
    ];

    /** [cargo masculino, cargo femenino, costo mínimo, costo máximo] en COP por hora */
    private const POSITIONS = [
        'ceo' => ['Gerente general', 'Gerente general', 180000, 180000],
        'coo' => ['Subgerente de operaciones', 'Subgerente de operaciones', 150000, 150000],
        'sales' => ['Ejecutivo comercial', 'Ejecutiva comercial', 62000, 68000],
        'leader' => ['Líder de proyecto', 'Líder de proyecto', 110000, 125000],
        'fullstack' => ['Desarrollador full stack', 'Desarrolladora full stack', 58000, 72000],
        'backend' => ['Desarrollador backend', 'Desarrolladora backend', 60000, 76000],
        'qa' => ['Analista de calidad (QA)', 'Analista de calidad (QA)', 42000, 52000],
        'infra' => ['Ingeniero de infraestructura', 'Ingeniera de infraestructura', 52000, 66000],
        'cloud' => ['Ingeniero cloud y DevOps', 'Ingeniera cloud y DevOps', 85000, 100000],
        'cyber' => ['Analista de ciberseguridad', 'Analista de ciberseguridad', 88000, 110000],
        'soc' => ['Analista SOC', 'Analista SOC', 60000, 72000],
    ];

    /** Especialidad de cada tipo de cargo (para filtrar la capacidad). */
    private const SPECIALTIES = [
        'ceo' => 'management', 'coo' => 'management', 'leader' => 'management',
        'sales' => 'commercial',
        'fullstack' => 'development', 'backend' => 'development',
        'qa' => 'qa',
        'infra' => 'infrastructure',
        'cloud' => 'cloud',
        'cyber' => 'cybersecurity', 'soc' => 'cybersecurity',
    ];

    private function createPeople(): void
    {
        $roles = Role::query()->whereIn('slug', array_keys(ModuleSetup::ROLES))->pluck('id', 'slug');
        $sites = Site::query()->pluck('id', 'code');
        $this->travel($this->today->subDays(300)->setTime(8, 0));

        foreach (self::PEOPLE as $index => [$name, $gender, $site, $role, $kind]) {
            $key = $kind.'-'.$index;
            $parts = explode(' ', $name);
            // nombre.apellido, usando el primer apellido (penúltima palabra).
            $email = Str::ascii(mb_strtolower($parts[0].'.'.$parts[count($parts) - 2])).'@'.self::EMAIL_DOMAIN;

            $user = new User(['name' => $name, 'email' => $email]);
            $user->forceFill(['is_active' => true])->save();
            $user->roles()->attach($roles[$role], ['created_at' => now()]);

            [$male, $female, $min, $max] = self::POSITIONS[$kind];
            $cost = (int) (round($this->random->getInt($min, $max) / 1000) * 1000);

            $profile = new StaffProfile(['position' => $gender === 'f' ? $female : $male, 'specialty' => self::SPECIALTIES[$kind]]);
            $profile->forceFill([
                'user_id' => $user->id,
                'site_id' => $sites[$site],
                'weekly_capacity_minutes' => (int) config('nova.projects.default_weekly_hours', 42) * 60,
                'hourly_cost' => $cost,
            ])->save();

            $this->people[$key] = $user;
            $this->profiles[$key] = ['position' => $profile->position, 'cost' => $cost, 'pool' => $kind, 'site' => $site];
            $this->load[$key] = 0;
        }
    }

    /** @return list<string> claves de las personas de un tipo de cargo */
    private function pool(string $kind): array
    {
        return array_values(array_filter(array_keys($this->profiles), fn (string $key) => $this->profiles[$key]['pool'] === $kind));
    }

    private function person(string $kind, int $nth = 0): string
    {
        return $this->pool($kind)[$nth];
    }

    // ------------------------------------------------------------------
    // Clientes y contratos
    // ------------------------------------------------------------------

    /** [clave, nombre, sector, sede] — nombres inventados para la demostración */
    private const CLIENTS = [
        ['banco', 'Banco Cordillera Andina', ClientSector::Financial, 'medellin'],
        ['coop', 'Cooperativa Financiera Altamira', ClientSector::Financial, 'medellin'],
        ['fondo', 'Fondo Prisma Capital', ClientSector::Financial, 'bogota'],
        ['tiendas', 'Tiendas Guayacán', ClientSector::Retail, 'medellin'],
        ['moda', 'Moda Nativa', ClientSector::Retail, 'bogota'],
        ['mercados', 'Mercados Río Claro', ClientSector::Retail, 'medellin'],
        ['cafe', 'Café Altiplano', ClientSector::Sme, 'medellin'],
        ['clinica', 'Clínica Dental Sonrisa Plena', ClientSector::Sme, 'bogota'],
        ['transportes', 'Transportes Ruta Norte', ClientSector::Other, 'bogota'],
        ['constructora', 'Constructora Horizonte Verde', ClientSector::Sme, 'medellin'],
        ['agro', 'Agroindustrial Valle Dorado', ClientSector::Other, 'medellin'],
        ['colegio', 'Colegio Los Robles', ClientSector::Other, 'medellin'],
    ];

    private function createClients(): void
    {
        $sites = Site::query()->pluck('id', 'code');

        foreach (self::CLIENTS as $index => [$key, $name, $sector, $site]) {
            $client = new Client(['name' => $name, 'sector' => $sector->value]);
            // Sin NIT: un NIT inventado podría coincidir con el de una empresa real.
            $client->forceFill([
                'code' => sprintf('%sCLI-%03d', self::CODE_PREFIX, $index + 1),
                'site_id' => $sites[$site],
                'is_active' => true,
                'created_by' => $this->people[$this->salesFor($site)]->id,
            ])->save();

            $this->clients[$key] = $client;
            $this->createContacts($client, $key, $index);
        }
    }

    /** [nombre, género] para los contactos de los clientes (también ficticios). */
    private const CONTACT_NAMES = [
        ['Lucía Páez', 'f'], ['Hernán Salcedo', 'm'], ['Gloria Inés Bustos', 'f'], ['Mauricio Lemus', 'm'],
        ['Adriana Pulido', 'f'], ['Óscar Benavides', 'm'], ['Claudia Marcela Rivas', 'f'], ['Germán Olaya', 'm'],
        ['Patricia Quiroga', 'f'], ['Jaime Arbeláez', 'm'], ['Sandra Milena Cuervo', 'f'], ['Rodrigo Vallejo', 'm'],
        ['Liliana Pacheco', 'f'], ['Fabio Ortega', 'm'], ['Marcela Duarte', 'f'], ['Iván Darío Lozada', 'm'],
        ['Beatriz Cifuentes', 'f'], ['Wilson Peñaloza', 'm'], ['Viviana Rengifo', 'f'], ['Álvaro Castañeda', 'm'],
        ['Constanza Mora', 'f'], ['Édgar Sarmiento', 'm'], ['Yolanda Barrera', 'f'], ['Gustavo Villamil', 'm'],
        ['Nubia Acevedo', 'f'], ['Humberto Galvis', 'm'], ['Diana Lucía Prieto', 'f'], ['Ernesto Rozo', 'm'],
        ['Mónica Patiño', 'f'], ['Luis Alberto Cano', 'm'], ['Esperanza Ruiz', 'f'], ['Arturo Medina', 'm'],
        ['Pilar Montenegro', 'f'], ['Fernando Carvajal', 'm'], ['Rocío Bermúdez', 'f'], ['Javier Londoño', 'm'],
        ['Teresa Avendaño', 'f'], ['Ricardo Bonilla', 'm'], ['Carmen Elisa Toro', 'f'], ['Héctor Fajardo', 'm'],
        ['Ángela María Rosero', 'f'], ['Nelson Guzmán', 'm'], ['Martha Lucía Henao', 'f'], ['Andrés Camacho', 'm'],
        ['Silvia Restrepo', 'f'], ['Raúl Echeverry', 'm'], ['Olga Lucía Parra', 'f'], ['Camilo Andrade', 'm'],
    ];

    /**
     * Cada cliente tiene un contacto comercial (el principal), uno técnico y
     * uno de facturación; los clientes financieros tienen además un directivo.
     * Correos en un subdominio de demostración y teléfonos con el bloque 555,
     * que no se asigna a líneas reales.
     */
    private function createContacts(Client $client, string $key, int $index): void
    {
        $roles = [
            [ContactType::Commercial, 'Jefe de compras', 'Jefa de compras'],
            [ContactType::Technical, 'Coordinador de tecnología', 'Coordinadora de tecnología'],
            [ContactType::Billing, 'Analista de cuentas por pagar', 'Analista de cuentas por pagar'],
        ];

        if ($client->sector === ClientSector::Financial) {
            $roles[] = [ContactType::Executive, 'Gerente de operaciones', 'Gerente de operaciones'];
        }

        foreach ($roles as $i => [$type, $male, $female]) {
            [$name, $gender] = self::CONTACT_NAMES[($index * 4 + $i) % count(self::CONTACT_NAMES)];
            $parts = explode(' ', $name);

            $contact = new ClientContact([
                'type' => $type->value,
                'name' => $name,
                'position' => $gender === 'f' ? $female : $male,
                'email' => Str::ascii(mb_strtolower($parts[0].'.'.end($parts))).'@'.$key.'.'.self::EMAIL_DOMAIN,
                'phone' => sprintf('+57 601 555 %04d', 100 + $index * 10 + $i),
            ]);
            $contact->forceFill(['client_id' => $client->id, 'is_primary' => $i === 0])->save();
        }
    }

    private function salesFor(string $site): string
    {
        return $site === 'bogota' ? $this->person('sales', 2) : $this->person('sales', 0);
    }

    /**
     * El plan de la demo: contratos, proyectos, equipos y casos especiales.
     *
     * Contratos: [clave, cliente, nombre, línea, cobro, cobertura, inicio (días atrás),
     *             estado final, extras]
     * Proyectos: [clave, contrato, nombre, líder (n.º), equipo [[cargo, personas, %]...]]
     *
     * @return array{contracts: list<array>, projects: list<array>}
     */
    private function planWork(): array
    {
        return [
            'contracts' => [
                ['soporte-banco', 'banco', 'Soporte de infraestructura 24/7', 'infrastructure_support', 'monthly_recurring', '24x7', 200, 'active', ['fee' => 32000000, 'hours' => 300]],
                ['pentest-banco', 'banco', 'Pruebas de penetración 2026', 'cybersecurity', 'hour_bag', 'business_hours', 80, 'active', ['rate' => 210000, 'target' => 0.55]],
                ['portal-coop', 'coop', 'Portal de asociados', 'custom_software', 'hour_bag', 'business_hours', 150, 'active', ['rate' => 175000, 'target' => 0.93]],
                ['nube-coop', 'coop', 'Operación de nube', 'cloud_services', 'monthly_recurring', 'extended', 170, 'active', ['fee' => 24000000, 'hours' => null]],
                ['soc-fondo', 'fondo', 'Centro de operaciones de seguridad (SOC)', 'cybersecurity', 'monthly_recurring', '24x7', 130, 'active', ['fee' => 38000000, 'hours' => 340]],
                ['app-tiendas', 'tiendas', 'App de fidelización', 'custom_software', 'hour_bag', 'business_hours', 160, 'active', ['rate' => 170000, 'target' => 0.78, 'top_up_days_ago' => 42]],
                ['soporte-tiendas', 'tiendas', 'Soporte de infraestructura de tiendas', 'infrastructure_support', 'monthly_recurring', 'business_hours', 220, 'active', ['fee' => 16000000, 'hours' => 150]],
                ['nube-moda', 'moda', 'Plataforma en la nube', 'cloud_services', 'monthly_recurring', 'extended', 100, 'active', ['fee' => 28000000, 'hours' => 180]],
                ['pos-mercados', 'mercados', 'Integración POS-ERP', 'custom_software', 'hour_bag', 'business_hours', 70, 'active', ['rate' => 175000, 'target' => 0.45]],
                ['soporte-mercados', 'mercados', 'Soporte de infraestructura', 'infrastructure_support', 'monthly_recurring', 'extended', 190, 'active', ['fee' => 24000000, 'hours' => 300, 'upgrade_days_ago' => 60, 'upgrade_fee' => 32000000]],
                ['hosting-cafe', 'cafe', 'Hosting y respaldo en la nube', 'cloud_services', 'monthly_recurring', 'business_hours', 240, 'active', ['fee' => 11000000, 'hours' => null]],
                ['soporte-clinica', 'clinica', 'Soporte técnico', 'infrastructure_support', 'hour_bag', 'business_hours', 90, 'active', ['rate' => 140000, 'target' => 0.6]],
                ['iso-transportes', 'transportes', 'Evaluación ISO 27001', 'cybersecurity', 'hour_bag', 'business_hours', 60, 'active', ['rate' => 210000, 'target' => 0.4]],
                ['obras-constructora', 'constructora', 'Control de obras', 'custom_software', 'hour_bag', 'business_hours', 120, 'active', ['rate' => 170000, 'target' => 0.68]],
                ['monitoreo-agro', 'agro', 'Monitoreo de nube', 'cloud_services', 'monthly_recurring', 'extended', 75, 'active', ['fee' => 15000000, 'hours' => 80]],
                ['inversionistas-fondo', 'fondo', 'Portal de inversionistas', 'custom_software', 'hour_bag', 'business_hours', -15, 'draft', ['rate' => 180000, 'fixed_hours' => 200]],
                ['auditoria-moda', 'moda', 'Auditoría de seguridad', 'cybersecurity', 'hour_bag', 'business_hours', 125, 'finished', ['rate' => 210000, 'target' => 0.97, 'end_days_ago' => 40]],
                ['soporte-colegio', 'colegio', 'Soporte de infraestructura', 'infrastructure_support', 'monthly_recurring', 'business_hours', 150, 'suspended', ['fee' => 7500000, 'hours' => 40, 'suspend_days_ago' => 21]],
            ],
            'projects' => [
                ['p-soporte-banco', 'soporte-banco', 'Soporte 24/7 Banco Cordillera', 0, [['infra', 3, 50]]],
                ['p-pentest-web', 'pentest-banco', 'Pentest de aplicaciones web', 3, [['cyber', 2, 50]]],
                ['p-pentest-interno', 'pentest-banco', 'Pentest de infraestructura interna', 3, [['cyber', 1, 40]]],
                ['p-portal', 'portal-coop', 'Portal de asociados', 1, [['fullstack', 4, 80], ['backend', 2, 60], ['qa', 1, 50]]],
                ['p-nube-coop', 'nube-coop', 'Operación nube Altamira', 2, [['cloud', 2, 40]]],
                ['p-soc', 'soc-fondo', 'SOC Fondo Prisma', 5, [['soc', 2, 80], ['cyber', 1, 30]]],
                ['p-app', 'app-tiendas', 'App de fidelización Guayacán', 1, [['fullstack', 3, 80], ['backend', 1, 50], ['qa', 1, 40]]],
                ['p-soporte-tiendas', 'soporte-tiendas', 'Soporte tiendas Guayacán', 0, [['infra', 2, 40]]],
                ['p-nube-moda', 'nube-moda', 'Nube Moda Nativa', 4, [['cloud', 2, 50]]],
                ['p-pos', 'pos-mercados', 'Integración POS-ERP', 2, [['backend', 2, 50], ['fullstack', 1, 60]]],
                ['p-soporte-mercados', 'soporte-mercados', 'Soporte 24/7 Mercados Río Claro', 0, [['infra', 3, 50]]],
                ['p-hosting', 'hosting-cafe', 'Hosting Café Altiplano', 4, [['cloud', 1, 30]]],
                ['p-clinica', 'soporte-clinica', 'Soporte Clínica Sonrisa Plena', 5, [['infra', 1, 30]]],
                ['p-iso', 'iso-transportes', 'Evaluación ISO 27001', 3, [['cyber', 2, 40]]],
                ['p-obras', 'obras-constructora', 'Control de obras: plataforma web', 2, [['fullstack', 2, 60], ['qa', 1, 30]]],
                ['p-obras-movil', 'obras-constructora', 'Control de obras: app móvil', 1, [['fullstack', 2, 40]]],
                ['p-monitoreo', 'monitoreo-agro', 'Monitoreo nube Valle Dorado', 4, [['cloud', 1, 40]]],
                ['p-inversionistas', 'inversionistas-fondo', 'Portal de inversionistas', 3, []],
                ['p-auditoria', 'auditoria-moda', 'Auditoría de seguridad 2026', 5, [['cyber', 2, 60]]],
                ['p-colegio', 'soporte-colegio', 'Soporte Colegio Los Robles', 0, [['infra', 1, 20]]],
            ],
        ];
    }

    /**
     * @param  array{contracts: list<array>, projects: list<array>}  $plan
     * @param  list<array<string, mixed>>  $rows
     */
    private function createContractsAndProjects(array $plan, array $rows): void
    {
        $manager = $this->people[$this->person('ceo')];
        $deputy = $this->people[$this->person('coo')];
        $sequence = 0;

        foreach ($plan['contracts'] as [$key, $clientKey, $name, $line, $billing, $coverage, $startAgo, $status, $extra]) {
            $sequence++;
            $client = $this->clients[$clientKey];
            $clientSite = $this->siteOfClient($clientKey);
            $sales = $this->people[$this->salesFor($clientSite)];
            $approver = $clientSite === 'bogota' ? $deputy : $manager;
            $start = $this->today->subDays($startAgo);

            // El tamaño de las bolsas se calcula a partir de las horas que se
            // van a generar, para que cada caso quede en el % que la demo necesita.
            $hours = $billing === 'hour_bag' ? $this->bagHours($key, $extra, $plan, $rows) : null;

            $this->travel($startAgo > 0 ? $start->setTime(9, 0) : $this->today->subDays(3)->setTime(9, 0));
            $contract = $this->contractService->create($sales, $client, [
                'code' => sprintf('%sCT-%03d', self::CODE_PREFIX, $sequence),
                'name' => $name,
                'service_line' => $line,
                'billing_model' => $billing,
                'coverage' => $coverage,
                'start_date' => $start->toDateString(),
                'end_date' => isset($extra['end_days_ago']) ? $this->today->subDays($extra['end_days_ago'])->toDateString() : null,
                'monthly_fee' => $extra['fee'] ?? null,
                'included_minutes_per_month' => isset($extra['hours']) ? $extra['hours'] * 60 : null,
                'initial_minutes' => $hours ? $hours['initial'] * 60 : null,
                'initial_amount' => $hours ? $hours['initial'] * $extra['rate'] : null,
                'notes' => 'Datos de demostración.',
            ]);

            if ($status !== 'draft') {
                $this->travel($start->addDays(2)->setTime(10, 30));
                $this->contractService->changeStatus($approver, $contract, ContractStatus::Active, 'Contrato firmado por el cliente.');
                $this->replayContractTimeline($contract, $extra, $start, $sales, $approver, $hours);
            }

            $this->contracts[$key] = $contract->fresh();
        }

        foreach ($plan['projects'] as $index => [$key, $contractKey, $name, $leaderIndex]) {
            $contract = $this->contracts[$contractKey];
            $status = match ($contract->status) {
                ContractStatus::Draft => ProjectStatus::Planned,
                ContractStatus::Finished => ProjectStatus::Closed,
                ContractStatus::Suspended => ProjectStatus::OnHold,
                default => ProjectStatus::Active,
            };

            $created = CarbonImmutable::parse($contract->start_date->toDateString(), BusinessCalendar::timezone())->addDays(3)->min($this->today);
            $this->travel($created->setTime(11, 0));

            $project = new Project([
                'name' => $name,
                'kind' => $contract->isMonthly() || $contractKey === 'soporte-clinica' ? ProjectKind::OngoingService->value : ProjectKind::Project->value,
                'start_date' => $contract->start_date->toDateString(),
                'end_date' => $contract->end_date?->toDateString(),
                'budget_minutes' => null,
                'description' => 'Datos de demostración.',
            ]);
            $project->forceFill([
                'contract_id' => $contract->id,
                'code' => sprintf('%sPRY-%03d', self::CODE_PREFIX, $index + 1),
                'status' => $status,
                'leader_id' => $this->people[$this->person('leader', $leaderIndex)]->id,
                'site_id' => $this->clients[$this->clientKeyOf($contractKey, $plan)]->site_id,
            ])->save();

            $this->projects[$key] = $project;
        }
    }

    /**
     * Eventos del contrato después de activarse, en orden cronológico: los
     * meses que se van abriendo, el cambio de cobertura, la recarga de la
     * bolsa, la suspensión o el cierre.
     */
    private function replayContractTimeline(Contract $contract, array $extra, CarbonImmutable $start, User $sales, User $approver, ?array $hours): void
    {
        $events = [];

        if ($contract->isMonthly()) {
            for ($month = $start->startOfMonth()->addMonth(); $month->lte($this->today); $month = $month->addMonth()) {
                $events[] = [$month->setTime(0, 15), 'open_month'];
            }
        }

        if (isset($extra['upgrade_days_ago'])) {
            $events[] = [$this->today->subDays($extra['upgrade_days_ago'])->setTime(16, 0), 'upgrade'];
        }

        if (isset($extra['top_up_days_ago']) && $hours) {
            $events[] = [$this->today->subDays($extra['top_up_days_ago'])->setTime(15, 0), 'top_up'];
        }

        if (isset($extra['suspend_days_ago'])) {
            $events[] = [$this->today->subDays($extra['suspend_days_ago'])->setTime(9, 30), 'suspend'];
        }

        if (isset($extra['end_days_ago'])) {
            $events[] = [$this->today->subDays($extra['end_days_ago'])->setTime(17, 0), 'finish'];
        }

        usort($events, fn ($a, $b) => $a[0] <=> $b[0]);

        foreach ($events as [$moment, $event]) {
            $this->travel($moment);
            $contract->refresh();

            match ($event) {
                'open_month' => $this->periods->ensureFor($contract, $moment),
                'upgrade' => $this->contractService->update($sales, $contract, [
                    'coverage' => '24x7',
                    'monthly_fee' => $extra['upgrade_fee'],
                ], 'Temporada alta: el cliente amplía la cobertura a 24/7. Aplica desde el próximo mes.'),
                'top_up' => $this->contractService->addHours(
                    $sales, $contract, HourMovementType::TopUp,
                    $hours['top_up'] * 60, $hours['top_up'] * $extra['rate'], $moment->toDateString(),
                    'Orden de compra OC-2026-118: el cliente amplía el alcance con un módulo de referidos.',
                ),
                'suspend' => $this->contractService->changeStatus($approver, $contract, ContractStatus::Suspended, 'El cliente pausa el servicio mientras aprueba el presupuesto del próximo año.'),
                'finish' => $this->contractService->changeStatus($approver, $contract, ContractStatus::Finished, 'Entrega final aceptada por el cliente.'),
            };
        }
    }

    // ------------------------------------------------------------------
    // Asignaciones y horas
    // ------------------------------------------------------------------

    /** @var array<string, list<array{person: string, percent: int, from: CarbonImmutable, to: CarbonImmutable|null, leader: bool}>> */
    private array $teams = [];

    /**
     * Arma los equipos de cada proyecto eligiendo a las personas menos
     * cargadas del cargo pedido, sin pasar del 100 %. Los proyectos cerrados
     * no cuentan para la carga actual (esas personas ya quedaron libres).
     *
     * Se calcula una sola vez y lo usan tanto las asignaciones como las horas.
     *
     * @param  array{contracts: list<array>, projects: list<array>}  $plan
     * @return array<string, list<array>>
     */
    private function teams(array $plan): array
    {
        if ($this->teams !== []) {
            return $this->teams;
        }

        $contracts = collect($plan['contracts'])->keyBy(0);
        $load = array_fill_keys(array_keys($this->profiles), 0);

        foreach ($plan['projects'] as [$key, $contractKey, , $leaderIndex, $team]) {
            $contract = $contracts[$contractKey];
            $start = $this->today->subDays($contract[6]);
            $end = isset($contract[8]['end_days_ago']) ? $this->today->subDays($contract[8]['end_days_ago']) : null;
            $suspendedAt = isset($contract[8]['suspend_days_ago']) ? $this->today->subDays($contract[8]['suspend_days_ago']) : null;
            $counts = $end === null;
            $members = [];

            foreach ($team as [$kind, $count, $percent]) {
                $candidates = $this->pool($kind);
                usort($candidates, fn ($a, $b) => [$load[$a], $a] <=> [$load[$b], $b]);

                foreach (array_slice(array_values(array_filter($candidates, fn ($c) => ! $counts || $load[$c] + $percent <= 100)), 0, $count) as $person) {
                    $members[] = ['person' => $person, 'percent' => $percent, 'from' => $start, 'to' => $end ?? $suspendedAt, 'leader' => false];

                    if ($counts) {
                        $load[$person] += $percent;
                    }
                }
            }

            // El líder dedica una parte de su tiempo a gestionar cada proyecto.
            if ($team !== []) {
                $members[] = ['person' => $this->person('leader', $leaderIndex), 'percent' => 15, 'from' => $start, 'to' => $end ?? $suspendedAt, 'leader' => true];
            }

            $this->teams[$key] = $members;
        }

        // Caso de la demo: una persona del Portal de asociados (ya al 80 %)
        // recibe un 40 % adicional en la app móvil por una urgencia.
        $person = collect($this->teams['p-portal'])->first(fn ($m) => ! $m['leader'] && $this->profiles[$m['person']]['pool'] === 'fullstack')['person'];
        $this->teams['p-obras-movil'][] = [
            'person' => $person, 'percent' => 40, 'from' => $this->today->subDays(20), 'to' => null, 'leader' => false, 'overallocation' => true,
        ];
        $load[$person] += 40;

        $this->load = $load;

        return $this->teams;
    }

    /**
     * Las asignaciones normales se crean directamente. La sobreasignación se
     * registra con el mismo servicio de la API, confirmándola, para que quede
     * en la auditoría como pasaría en la realidad.
     *
     * @param  array{contracts: list<array>, projects: list<array>}  $plan
     * @return array{name: string, percent: int}
     */
    private function createAllocations(array $plan): array
    {
        $overallocated = null;

        foreach ($this->teams($plan) as $projectKey => $members) {
            $project = $this->projects[$projectKey];

            foreach ($members as $member) {
                $this->travel($member['from']->addDays(3)->min($this->today)->setTime(11, 30));
                $data = [
                    'role_label' => $member['leader'] ? 'Líder' : $this->profiles[$member['person']]['position'],
                    'allocation_percent' => $member['percent'],
                    'start_date' => $member['from']->toDateString(),
                    'end_date' => $member['to']?->toDateString(),
                ];

                if ($member['overallocation'] ?? false) {
                    $this->travel($member['from']->setTime(8, 45));
                    $this->allocationService->assign(
                        $project->leader()->firstOrFail(),
                        $project,
                        ['user_id' => $this->people[$member['person']]->id, 'role_label' => 'Refuerzo por urgencia'] + $data,
                        confirmOverallocation: true,
                    );
                    $overallocated = ['name' => $this->people[$member['person']]->name, 'percent' => $this->load[$member['person']]];

                    continue;
                }

                $allocation = new Allocation($data);
                $allocation->forceFill([
                    'project_id' => $project->id,
                    'user_id' => $this->people[$member['person']]->id,
                    'created_by' => $project->leader_id,
                ])->save();
            }
        }

        return $overallocated;
    }

    /** Actividades típicas por línea de servicio, para las descripciones. */
    private const TASKS = [
        'custom_software' => [
            'Desarrollo de historias de usuario del sprint',
            'Revisión de código y corrección de defectos',
            'Pruebas funcionales del módulo',
            'Reunión de planeación con el cliente',
            'Integración con el servicio de pagos',
            'Ajustes de interfaz solicitados por el cliente',
        ],
        'infrastructure_support' => [
            'Atención de tickets de soporte',
            'Actualización de parches en servidores',
            'Revisión de copias de seguridad',
            'Mantenimiento preventivo de la red',
            'Configuración de equipos nuevos',
        ],
        'cloud_services' => [
            'Monitoreo de recursos en la nube',
            'Optimización de costos de la suscripción',
            'Automatización de despliegues',
            'Revisión de alertas de disponibilidad',
            'Ajuste de políticas de escalado',
        ],
        'cybersecurity' => [
            'Análisis de vulnerabilidades',
            'Revisión de alertas del SIEM',
            'Pruebas de intrusión controladas',
            'Elaboración del informe de hallazgos',
            'Revisión de controles de acceso',
        ],
    ];

    private const REJECTIONS = [
        'Falta el número del ticket en la descripción.',
        'Las horas no coinciden con el reporte enviado al cliente.',
        'Este trabajo corresponde a otro proyecto; regístralo allí.',
        'Detalla qué se hizo: la descripción es muy general.',
    ];

    /**
     * Inserta las horas planeadas en bloques de 500 (miles de filas en
     * segundos). Aquí cada fila recibe el id real de su proyecto.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int> registros por estado
     */
    private function insertTimeEntries(array $rows): array
    {
        $counts = array_fill_keys(TimeEntryStatus::values(), 0);

        $rows = array_map(function (array $row) use (&$counts) {
            $counts[$row['status']]++;
            $row['project_id'] = $this->projects[$row['_project']]->id;
            unset($row['_project'], $row['_reviewer']);

            return $row;
        }, $rows);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('time_entries')->insert($chunk);
        }

        return $counts;
    }

    /** @var list<array<string, mixed>>|null */
    private ?array $plannedEntries = null;

    /**
     * @param  array{contracts: list<array>, projects: list<array>}  $plan
     * @return list<array<string, mixed>>
     */
    private function plannedEntries(array $plan): array
    {
        if ($this->plannedEntries !== null) {
            return $this->plannedEntries;
        }

        $contracts = collect($plan['contracts'])->keyBy(0);
        $projectsPlan = collect($plan['projects'])->keyBy(0);
        $rows = [];

        foreach ($this->teams($plan) as $projectKey => $members) {
            $contract = $contracts[$projectsPlan[$projectKey][1]];
            [$line, $coverage, $extra] = [$contract[3], $contract[5], $contract[8]];
            $leaderKey = $this->person('leader', $projectsPlan[$projectKey][3]);
            $tasks = self::TASKS[$line];

            foreach ($members as $memberIndex => $member) {
                $from = $member['from'];
                $to = ($member['to'] ?? $this->today)->min($this->today);

                for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                    $isWeekend = $day->isWeekend();
                    // En festivo nadie registra la jornada normal; los turnos
                    // de disponibilidad 24/7 sí se cubren.
                    $isHoliday = ColombianHolidays::isHoliday($day);
                    $is24x7 = $coverage === '24x7' || (isset($extra['upgrade_days_ago']) && $day->gte($this->today->subDays($extra['upgrade_days_ago'])));

                    // Jornada normal: entre semana, con algunas ausencias.
                    if (! $isWeekend && ! $isHoliday && $this->random->getInt(1, 100) > 4) {
                        $base = 42 / 5 * 60 * $member['percent'] / 100;
                        $minutes = $this->roundToStep($base * $this->random->getInt(85, 110) / 100);
                        $description = $member['leader'] ? 'Gestión del proyecto y seguimiento con el cliente' : $tasks[$this->random->getInt(0, count($tasks) - 1)];
                        $rows[] = $this->row($member['person'], $projectKey, $leaderKey, $day, $minutes, $description, false, false);
                    }

                    if ($member['leader'] || ! in_array($line, ['infrastructure_support', 'cybersecurity'], true) || ! $is24x7) {
                        continue;
                    }

                    // Turno de disponibilidad del fin de semana, rotando entre el equipo.
                    if (($isWeekend || $isHoliday) && ((int) $day->format('W') % max(1, count($members) - 1)) === $memberIndex) {
                        $rows[] = $this->row($member['person'], $projectKey, $leaderKey, $day, 240, 'Turno de disponibilidad 24/7', true, true);
                    }

                    // Incidentes en la noche.
                    if ($this->random->getInt(1, 100) <= 8) {
                        $rows[] = $this->row($member['person'], $projectKey, $leaderKey, $day, $this->roundToStep($this->random->getInt(45, 150)), 'Atención de incidente fuera de horario', true, false);
                    }
                }
            }
        }

        // Algunos rechazos recientes para mostrar el flujo completo.
        $candidates = array_keys(array_filter($rows, fn ($r) => $r['status'] === TimeEntryStatus::Submitted->value && ! $r['is_on_call']));
        $this->random->shuffleArray($candidates);

        foreach (array_slice($candidates, 0, 7) as $i => $index) {
            $rows[$index]['status'] = TimeEntryStatus::Rejected->value;
            $rows[$index]['rejection_reason'] = self::REJECTIONS[$i % count(self::REJECTIONS)];
            $rows[$index]['reviewed_by'] = $rows[$index]['_reviewer'];
            $rows[$index]['reviewed_at'] = $rows[$index]['submitted_at'];
        }

        return $this->plannedEntries = $rows;
    }

    /**
     * Estado según la antigüedad: lo viejo está aprobado, la semana pasada
     * está en revisión y lo de ayer y hoy sigue en borrador.
     *
     * @return array<string, mixed>
     */
    private function row(string $person, string $projectKey, string $leaderKey, CarbonImmutable $day, int $minutes, string $description, bool $afterHours, bool $onCall): array
    {
        $age = (int) $day->diffInDays($this->today);
        $status = match (true) {
            $age >= 15 => TimeEntryStatus::Approved,
            $age >= 7 => $this->random->getInt(1, 100) <= 70 ? TimeEntryStatus::Approved : TimeEntryStatus::Submitted,
            $age >= 2 => TimeEntryStatus::Submitted,
            default => TimeEntryStatus::Draft,
        };

        // Las horas del líder las aprueba Gerencia (o Subgerencia en Bogotá).
        $reviewerKey = $person === $leaderKey
            ? ($this->profiles[$leaderKey]['site'] === 'bogota' ? $this->person('coo') : $this->person('ceo'))
            : $leaderKey;

        $created = $day->setTime(18, 0);
        $submitted = $status === TimeEntryStatus::Draft ? null : $day->addDay()->setTime(18, 30)->min(CarbonImmutable::now());
        $reviewed = $status === TimeEntryStatus::Approved ? $day->addDays($this->random->getInt(2, 4))->setTime(10, 0)->min(CarbonImmutable::now()) : null;

        return [
            'user_id' => $this->people[$person]->id,
            'work_date' => $day->toDateString(),
            'minutes' => $minutes,
            'description' => $description,
            'is_billable' => true,
            'is_after_hours' => $afterHours,
            'is_on_call' => $onCall,
            'status' => $status->value,
            'submitted_at' => $submitted?->utc()->toDateTimeString(),
            'reviewed_by' => $reviewed ? $this->people[$reviewerKey]->id : null,
            'reviewed_at' => $reviewed?->utc()->toDateTimeString(),
            'rejection_reason' => null,
            'cost_rate' => $status === TimeEntryStatus::Approved ? $this->profiles[$person]['cost'] : null,
            'created_at' => $created->utc()->toDateTimeString(),
            'updated_at' => ($reviewed ?? $submitted ?? $created)->utc()->toDateTimeString(),
            '_reviewer' => $this->people[$reviewerKey]->id,
            '_project' => $projectKey,
        ];
    }

    /**
     * Horas de cada bolsa según las horas aprobadas que tendrá y el % de
     * consumo que se quiere mostrar (por ejemplo, 93 % en el proyecto en riesgo).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{initial: int, top_up: int}
     */
    private function bagHours(string $contractKey, array $extra, array $plan, array $rows): array
    {
        if (isset($extra['fixed_hours'])) {
            return ['initial' => $extra['fixed_hours'], 'top_up' => 0];
        }

        $projectKeys = collect($plan['projects'])->where(1, $contractKey)->pluck(0)->all();
        $approvedRows = array_filter($rows, fn ($r) => $r['status'] === TimeEntryStatus::Approved->value && in_array($r['_project'], $projectKeys, true));

        $approved = array_sum(array_column($approvedRows, 'minutes')) / 60;
        $total = (int) (ceil($approved / $extra['target'] / 10) * 10);

        if (! isset($extra['top_up_days_ago'])) {
            return ['initial' => max(10, $total), 'top_up' => 0];
        }

        // Con recarga: la bolsa inicial estaba casi agotada cuando el cliente compró más horas.
        $limit = $this->today->subDays($extra['top_up_days_ago'])->toDateString();
        $before = array_sum(array_column(array_filter($approvedRows, fn ($r) => $r['work_date'] < $limit), 'minutes')) / 60;
        $initial = (int) (ceil($before / 0.96 / 10) * 10);

        return ['initial' => $initial, 'top_up' => max(40, $total - $initial)];
    }

    private function roundToStep(float $minutes): int
    {
        $step = (int) config('nova.timesheets.step_minutes', 15);

        return (int) max($step, round($minutes / $step) * $step);
    }

    // ------------------------------------------------------------------
    // Presentador y resumen
    // ------------------------------------------------------------------

    /**
     * La persona que presenta (una cuenta real, ya autorizada) queda como
     * líder de dos proyectos con horas pendientes, para poder aprobarlas en
     * vivo. No se le cambian los roles.
     */
    private function attachPresenter(User $presenter): void
    {
        $this->travel($this->originalNow);

        if (! $presenter->staffProfile()->exists()) {
            $profile = new StaffProfile(['position' => 'Líder de proyecto', 'specialty' => 'management']);
            $profile->forceFill([
                'user_id' => $presenter->id,
                'site_id' => Site::query()->where('code', 'medellin')->value('id'),
                'weekly_capacity_minutes' => (int) config('nova.projects.default_weekly_hours', 42) * 60,
                'hourly_cost' => 120000,
            ])->save();
        }

        foreach (['p-portal', 'p-soporte-banco'] as $key) {
            $this->projects[$key]->forceFill(['leader_id' => $presenter->id])->save();
        }
    }

    private function summary(array $entries, array $overallocated, ?User $presenter): array
    {
        $risk = $this->projects['p-portal'];
        $approved = (int) TimeEntry::query()->where('project_id', $risk->id)->where('status', 'approved')->sum('minutes');
        $purchased = (int) $this->contracts['portal-coop']->hourMovements()->sum('minutes');

        return [
            'people' => count($this->people),
            'by_site' => array_count_values(array_column($this->profiles, 'site')),
            'clients' => count($this->clients),
            'contracts' => count($this->contracts),
            'projects' => count($this->projects),
            'allocations' => Allocation::query()->whereIn('project_id', collect($this->projects)->pluck('id'))->count(),
            'entries' => $entries,
            'cases' => [
                'Proyecto en riesgo' => sprintf('%s: %d %% de la bolsa consumida', $risk->name, (int) round($approved / max(1, $purchased) * 100)),
                'Sobreasignación' => sprintf('%s al %d %% de su capacidad', $overallocated['name'], $overallocated['percent']),
                'Recarga de bolsa' => 'App de fidelización (Tiendas Guayacán)',
                'Cambio de cobertura a 24/7' => 'Soporte de infraestructura (Mercados Río Claro)',
                'Mensualidad sin horas incluidas' => 'Operación de nube (Cooperativa Altamira)',
                'Contrato suspendido' => 'Soporte de infraestructura (Colegio Los Robles)',
                'Horas pendientes de aprobar' => (string) $entries[TimeEntryStatus::Submitted->value],
                'Presentador' => $presenter ? "{$presenter->name}, líder de Portal de asociados y Soporte 24/7 Banco Cordillera" : 'No se indicó (--presenter=correo)',
            ],
        ];
    }

    private function siteOfClient(string $clientKey): string
    {
        return collect(self::CLIENTS)->firstWhere(0, $clientKey)[3];
    }

    private function clientKeyOf(string $contractKey, array $plan): string
    {
        return collect($plan['contracts'])->firstWhere(0, $contractKey)[1];
    }

    /**
     * "Viaja" a un momento para que created_at, el historial y los periodos
     * queden con fechas creíbles. Al terminar se restaura el reloj original.
     */
    private function travel(?CarbonInterface $moment): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }
}
