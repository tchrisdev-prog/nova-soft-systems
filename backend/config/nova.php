<?php

/*
|--------------------------------------------------------------------------
| Nova Sync Systems — configuración del ERP
|--------------------------------------------------------------------------
|
| Catálogo de módulos y acciones. Es la fuente de verdad para generar los
| permisos en base de datos (php artisan nova:sync-permissions).
|
| Para incorporar un módulo nuevo: agrega una entrada en "modules", define
| sus acciones y ejecuta el comando de sincronización. La lógica de
| autorización no necesita cambios: los permisos se identifican por el
| código "<modulo>.<accion>" (por ejemplo "inventory.view").
|
*/

return [

    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/'),

    'super_admin_role' => 'super-admin',

    'auth' => [
        // Si es false (recomendado), solo pueden ingresar cuentas previamente
        // autorizadas por un administrador o aprovisionadas por comando.
        // Si es true, cualquier cuenta del tenant configurado se registra en
        // su primer ingreso, sin roles asignados.
        'auto_provision_users' => (bool) env('NOVA_AUTO_PROVISION_USERS', false),

        // Dominios de correo permitidos (separados por coma). Vacío = cualquier
        // dominio del tenant configurado.
        'allowed_email_domains' => array_values(array_filter(array_map(
            fn ($domain) => strtolower(trim($domain)),
            explode(',', (string) env('NOVA_ALLOWED_EMAIL_DOMAINS', ''))
        ))),
    ],

    // Zona horaria del negocio. Define qué es "hoy" para el registro de horas
    // y el inicio de cada mes en las mensualidades, aunque el servidor use UTC.
    'timezone' => env('NOVA_TIMEZONE', 'America/Bogota'),

    'projects' => [
        // Jornada máxima legal en Colombia desde el 15 de julio de 2026.
        // Cada consultor puede tener una capacidad distinta en su perfil.
        'default_weekly_hours' => (int) env('NOVA_DEFAULT_WEEKLY_HOURS', 42),

        // A partir de este porcentaje de horas consumidas el proyecto se
        // muestra en riesgo.
        'risk_threshold' => 0.9,
    ],

    'clients' => [
        // Una bolsa de horas se marca en alerta desde este % de consumo.
        'bag_alert_threshold' => (float) env('NOVA_BAG_ALERT_THRESHOLD', 0.8),

        // Días de anticipación para avisar que un contrato está por vencer.
        'expiring_days' => (int) env('NOVA_CONTRACT_EXPIRING_DAYS', 30),
    ],

    'timesheets' => [
        // Las horas se registran en bloques de 15 minutos.
        'step_minutes' => 15,

        // Cuántos días hacia atrás se puede registrar horas. Evita que se
        // modifiquen meses ya facturados sin que nadie lo note.
        'max_backdate_days' => (int) env('NOVA_TIMESHEET_BACKDATE_DAYS', 31),
    ],

    'actions' => [
        'view' => 'Consultar',
        'create' => 'Crear',
        'update' => 'Actualizar',
        'delete' => 'Eliminar',
        'manage' => 'Administrar',
        // Acciones propias del módulo de proyectos
        'lead' => 'Liderar',
        'capacity' => 'Ver capacidad',
        'staff' => 'Gestionar perfiles',
        'financials' => 'Ver costos',
        'approve' => 'Aprobar',
        'approve_any' => 'Aprobar cualquiera',
    ],

    /*
    | status: active   → disponible y funcional.
    |         planned  → parte de la arquitectura, pendiente de desarrollo.
    |         inactive → deshabilitado temporalmente.
    */
    'modules' => [
        'access_control' => [
            'name' => 'Roles y permisos',
            'description' => 'Administración de roles, permisos y acceso a los módulos del ERP.',
            'icon' => 'shield',
            'status' => 'active',
            'sort_order' => 10,
            'actions' => [
                'view' => 'Consultar roles y sus permisos',
                'create' => 'Crear roles',
                'update' => 'Editar y activar o desactivar roles',
                'manage' => 'Asignar y retirar permisos de los roles',
            ],
        ],
        'users' => [
            'name' => 'Usuarios',
            'description' => 'Usuarios autorizados, su estado y los roles asignados.',
            'icon' => 'users',
            'status' => 'active',
            'sort_order' => 20,
            'actions' => [
                'view' => 'Consultar usuarios y su estado',
                'create' => 'Autorizar nuevas cuentas corporativas',
                'update' => 'Activar o desactivar usuarios',
                'delete' => 'Eliminar cuentas (se conserva su historial)',
                'manage' => 'Asignar y retirar roles a usuarios',
            ],
        ],
        'audit' => [
            'name' => 'Auditoría',
            'description' => 'Registro de operaciones administrativas sensibles.',
            'icon' => 'activity',
            'status' => 'active',
            'sort_order' => 30,
            'actions' => [
                'view' => 'Consultar el registro de auditoría',
            ],
        ],
        // Módulo de Proyectos y Capacidad. Además de las acciones estándar,
        // cada módulo declara las acciones propias del negocio (aprobar horas,
        // ver costos...). Las policies revisan estos permisos, nunca el nombre
        // de un rol, para que renombrar un rol no cambie lo que puede hacer.
        'clients' => [
            'name' => 'Clientes y contratos',
            'description' => 'Clientes, contratos por línea de servicio, bolsas de horas, mensualidades e historial de cambios.',
            'icon' => 'briefcase',
            'status' => 'active',
            'sort_order' => 100,
            'actions' => [
                'view' => 'Consultar clientes, contratos y su historial',
                'create' => 'Registrar clientes y contratos',
                'update' => 'Editar clientes y contratos',
                'delete' => 'Eliminar clientes y contratos sin movimientos',
                'manage' => 'Cambiar el estado de contratos y recargar bolsas de horas',
            ],
        ],
        'projects' => [
            'name' => 'Proyectos y capacidad',
            'description' => 'Proyectos y servicios continuos, asignación de consultores y disponibilidad por sede.',
            'icon' => 'folder',
            'status' => 'active',
            'sort_order' => 110,
            'actions' => [
                'view' => 'Consultar todos los proyectos',
                'create' => 'Crear proyectos',
                'update' => 'Editar proyectos',
                'delete' => 'Eliminar proyectos sin horas registradas',
                'manage' => 'Asignar consultores en cualquier proyecto',
                'lead' => 'Gestionar los proyectos que lidera',
                'capacity' => 'Consultar la disponibilidad de los consultores',
                'staff' => 'Editar perfiles de consultores (sede, cargo y capacidad)',
                'financials' => 'Ver costos internos, tarifas y márgenes',
            ],
        ],
        'timesheets' => [
            'name' => 'Registro de horas',
            'description' => 'Registro de horas 24/7, envío, aprobación y consumo de contratos.',
            'icon' => 'clock',
            'status' => 'planned',
            'sort_order' => 120,
            'actions' => [
                'create' => 'Registrar sus propias horas',
                'view' => 'Consultar las horas de todos los consultores',
                'approve' => 'Aprobar horas de los proyectos que lidera',
                'approve_any' => 'Aprobar horas de cualquier proyecto',
            ],
        ],
    ],

];
