# Nova Sync Systems — ERP empresarial

Plataforma ERP modular con control de acceso por roles y permisos e inicio de sesión corporativo con Microsoft 365 (Microsoft Entra ID).

| Capa | Tecnología |
| --- | --- |
| Frontend | React 19, React Router 8, Sass (CSS Modules), Vite 8 |
| Backend | Laravel 13 (PHP 8.3+), API REST, Laravel Sanctum (sesión SPA), Laravel Socialite |
| Autenticación | Microsoft Entra ID — OpenID Connect, Authorization Code + PKCE |
| Base de datos | PostgreSQL 15+ (recomendada) · SQLite para desarrollo rápido y pruebas |

---

## 1. Estructura del repositorio

```
nova-soft-systems/
├── docker-compose.yml       web (nginx) + app (PHP-FPM) + scheduler + db (PostgreSQL)
├── .env.docker.example      Variables para Docker (se copia como .env)
├── backend/                 API Laravel (Dockerfile y docker/php/ para la imagen)
│   ├── app/
│   │   ├── Auth/EntraId/        Proveedor OIDC, validación del id_token, configuración
│   │   ├── Console/Commands/    nova:super-admin, nova:sync-permissions, nova:setup-projects, nova:contracts-renew, nova:demo
│   │   ├── Enums/               Estados y catálogos del negocio (líneas de servicio, cobro, cobertura...)
│   │   ├── Exceptions/          Reglas de negocio con códigos de error estables
│   │   ├── Http/Controllers/    Api/V1/* y Auth/MicrosoftAuthController
│   │   ├── Http/Middleware/     Cuenta activa, permiso requerido
│   │   ├── Http/Requests/       Validación (Form Requests)
│   │   ├── Http/Resources/      Respuestas JSON consistentes
│   │   ├── Http/Support/        Formato uniforme de errores de la API
│   │   ├── Models/              Acceso (User, Role, Permission, Module, AuditLog) y proyectos (Client, Contract, Project, TimeEntry...)
│   │   ├── Policies/            Roles, usuarios, clientes, contratos, proyectos y horas
│   │   ├── Services/            Roles, usuarios, autorización, auditoría, login, contratos, horas, asignaciones, demo
│   │   └── Support/             Calendario del negocio (zona horaria de Colombia)
│   ├── config/nova.php          Catálogo de módulos y acciones (fuente de verdad de permisos)
│   ├── database/migrations/     Usuarios, módulos, permisos, roles, auditoría, sedes, clientes, contratos, proyectos, horas
│   ├── lang/es/                 Mensajes de validación en español
│   └── tests/                   162 pruebas (autenticación, autorización, roles, usuarios, clientes, contratos, proyectos, capacidad, comandos)
├── frontend/                Aplicación React (Dockerfile y docker/nginx.conf para la imagen)
│   └── src/
│       ├── components/          ui/ (reutilizables), landing/, admin/, roles/, users/, brand/
│       ├── config/              Constantes, catálogo público de módulos, navegación
│       ├── context/             AuthContext (sesión y permisos), ToastContext
│       ├── hooks/               useAuth, useApiResource, useDebouncedValue, ...
│       ├── layouts/             AdminLayout (sidebar + barra superior)
│       ├── pages/               Landing, Login, Panel, Módulos, Roles, Usuarios, Auditoría
│       ├── routes/              Router, rutas protegidas y por permiso
│       ├── services/            Cliente HTTP (cookies + CSRF) y servicios por recurso
│       ├── styles/              Tokens, funciones, mixins y estilos base (Sass)
│       └── utils/               Formato, validaciones, mensajes
└── docs/
    ├── architecture.md      Decisiones técnicas, modelo de datos y API
    ├── entra-id-setup.md    Registro de la aplicación en Microsoft Entra ID
    ├── projects-module.md   Módulo de Proyectos y Capacidad: datos, permisos, reglas y API
    └── docker.md            Ejecución con Docker Compose
```

## 2. Requisitos

- PHP 8.3 o superior con extensiones `pdo_pgsql` (o `pdo_sqlite`), `mbstring`, `openssl`, `intl`, `xml`, `curl`.
- Composer 2.
- Node.js 22.22 o superior y npm.
- PostgreSQL 15 o superior (o SQLite para una prueba local).
- Un tenant de Microsoft 365 con permisos para registrar aplicaciones en Microsoft Entra ID.

## 3. Instalación

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Configura la base de datos en `.env` (PostgreSQL por defecto). Para una prueba local rápida con SQLite:

```bash
# en .env: DB_CONNECTION=sqlite (y comenta DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD)
touch database/database.sqlite
```

Crea las tablas, registra el catálogo de módulos y permisos y prepara el módulo de proyectos (sedes y plantillas de roles):

```bash
php artisan migrate
php artisan nova:sync-permissions --prune   # --prune retira los módulos que ya no están en el catálogo
php artisan nova:setup-projects
```

### Frontend

```bash
cd frontend
npm install
cp .env.example .env            # opcional: VITE_BACKEND_URL si Laravel no corre en :8000
```

## 4. Configuración de Microsoft 365 (Entra ID)

Sigue [docs/entra-id-setup.md](docs/entra-id-setup.md) y completa en `backend/.env`:

| Variable | Descripción |
| --- | --- |
| `MICROSOFT_TENANT_ID` | Directory (tenant) ID — GUID del tenant corporativo. No se aceptan `common` ni `organizations`. |
| `MICROSOFT_CLIENT_ID` | Application (client) ID del registro de aplicación. |
| `MICROSOFT_CLIENT_SECRET` | Secreto de cliente (valor, no el ID del secreto). |
| `MICROSOFT_REDIRECT_URI` | Debe coincidir exactamente con el Redirect URI registrado. En desarrollo: `http://localhost:5173/auth/microsoft/callback`. |
| `FRONTEND_URL` | URL del frontend (destino tras el inicio de sesión). |
| `SANCTUM_STATEFUL_DOMAINS` | Host:puerto del frontend (p. ej. `localhost:5173`). |
| `NOVA_ALLOWED_EMAIL_DOMAINS` | Opcional. Dominios permitidos, separados por coma. |
| `NOVA_AUTO_PROVISION_USERS` | `false` (recomendado): solo ingresan cuentas autorizadas previamente. |

Mientras estas variables no estén completas, la pantalla de acceso lo indica y el botón de Microsoft permanece deshabilitado; el sistema nunca simula un inicio de sesión.

## 5. Aprovisionar el primer Super Admin

No existen usuarios ni contraseñas predeterminadas. Desde la consola del servidor:

```bash
cd backend
php artisan nova:super-admin director.ti@tuempresa.com --name="Nombre Apellido"
```

El comando pide confirmación, autoriza la cuenta y le asigna el rol de sistema **Super Admin**. La persona ingresa con su cuenta de Microsoft 365; en ese primer ingreso se vincula su identidad (`oid` de Entra ID). El correo debe coincidir con su usuario (UPN) de Microsoft 365.

Después, el Super Admin autoriza al resto de cuentas desde **Usuarios → Autorizar cuenta** y les asigna roles.

## 6. Ejecución en desarrollo

```bash
# terminal 1
cd backend && php artisan serve          # http://localhost:8000

# terminal 2
cd frontend && npm run dev               # http://localhost:5173
```

Abre **http://localhost:5173**. Vite reenvía `/api`, `/auth` y `/sanctum` a Laravel, de modo que el navegador trabaja con un solo origen y la cookie de sesión funciona sin CORS.

### Con Docker

Todo el ERP (interfaz, API, programador de tareas y PostgreSQL) se levanta con `docker compose up -d --build` en `http://localhost:8080`, sin instalar PHP ni Node. Pasos, seguridad y uso en servidor en [docs/docker.md](docs/docker.md).

## 7. Pruebas y compilación

```bash
cd backend && php artisan test           # o: composer test
cd frontend && npm run build             # genera frontend/dist
```

## 8. Producción (resumen)

- Sirve `frontend/dist` y la API desde el **mismo dominio**; un proxy inverso envía `/api`, `/auth` y `/sanctum` a Laravel (`backend/public`). Ver ejemplo de Nginx en [docs/architecture.md](docs/architecture.md#despliegue).
- HTTPS obligatorio, `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`.
- Redirect URI de producción registrado en Entra ID (p. ej. `https://erp.tuempresa.com/auth/microsoft/callback`).
- En cada despliegue: `php artisan migrate --force && php artisan nova:sync-permissions && php artisan optimize`.
- Programador de tareas activo (`* * * * * php artisan schedule:run`): abre cada mes de las mensualidades (`nova:contracts-renew`).
- Nunca ejecutes `nova:demo` en producción (el comando se niega a hacerlo).
- Rota el secreto de cliente antes de su vencimiento (Entra ID lo exige).

## 9. Módulo de Proyectos y Capacidad

**Clientes y contratos** y **Proyectos y capacidad** están disponibles con sus pantallas. **Registro de horas** tiene la API lista y sigue como **En desarrollo** en la interfaz hasta tener pantallas. Detalle completo en [docs/projects-module.md](docs/projects-module.md).

| Módulo | Qué cubre |
| --- | --- |
| Clientes y contratos (`clients`) | Clientes con varios contactos y NIT validado, contratos por línea de servicio con código automático, bolsas de horas con recargas, mensualidades por periodo, alertas, exportación a Excel e historial de cambios de cada contrato. |
| Proyectos y capacidad (`projects`) | Proyectos y servicios continuos con código automático, estados y salud calculada; equipo con aviso de sobreasignación; capacidad semanal a 4/8/12 semanas descontando festivos de Colombia; búsqueda de disponibilidad; perfiles (sede, cargo, especialidad, jornada, costo con permiso). |
| Registro de horas (`timesheets`) | Registro 24/7 con marcas de *fuera de horario* y *disponibilidad*, envío, aprobación del líder (o Gerencia/Subgerencia) y consumo de cada contrato. |

**Plantillas de roles** (`php artisan nova:setup-projects`): Gerencia, Subgerencia, Comercial, Líder de proyecto y Consultor. Son un punto de partida: el Super Admin las ajusta en **Roles y permisos**, y volver a ejecutar el comando no pisa esos ajustes (salvo con `--reset-permissions`).

**Datos de demostración** (solo desarrollo o pruebas; el comando se niega en producción):

```bash
php artisan nova:demo                                   # 47 consultores, 12 clientes, 18 contratos, ~6.000 registros de horas
php artisan nova:demo --presenter=tu.correo@empresa.com # además, esa cuenta queda como líder de dos proyectos con horas por aprobar
php artisan nova:demo --reset                           # borra la demo anterior y la vuelve a generar
php artisan nova:demo --remove                          # solo la borra
php artisan nova:demo --reset --with-linked             # incluye contratos o proyectos creados en ensayos sobre datos de demostración
```

Todo lo de la demo es ficticio, usa el dominio `@demo.nexus.test` (esas cuentas no pueden iniciar sesión) y el prefijo `DEMO-` en los códigos.

## 10. Cómo agregar un módulo

1. Declara el módulo en `backend/config/nova.php` (código, nombre, estado, acciones).
2. Ejecuta `php artisan nova:sync-permissions`. Se crean los permisos `<modulo>.<accion>`; los roles existentes no cambian.
3. Protege sus endpoints con `->middleware('permission:<modulo>.view')` o con una Policy que use `$user->hasPermission('<modulo>.<accion>')`.
4. En el frontend, agrega su ruta en `src/config/navigation.js` y su página en `src/routes/AppRouter.jsx`. El menú lo muestra solo a quien tenga permisos en el módulo.

## 11. Estado de esta entrega

**Implementado y verificado**

- Landing page completa (navbar, hero con mapa de módulos, presentación, características, arquitectura modular, seguridad y acceso, llamada a la acción, footer), responsive y navegable con teclado.
- Pantalla de acceso con el flujo oficial de Microsoft (sin formularios de contraseña) y mensajes para cada error de autenticación.
- Backend: modelo de usuarios, módulos, permisos, roles y auditoría; API REST v1; validación con Form Requests; Policies, middleware y reglas contra escalada de privilegios; protección del último Super Admin; auditoría de operaciones sensibles; errores JSON uniformes.
- Integración OIDC con Entra ID: PKCE, `state`, `nonce`, validación de firma (JWKS con rotación de claves), emisor, audiencia y tenant; vinculación por `oid`.
- Panel administrativo: sidebar por permisos, barra superior con el usuario, panel con indicadores reales, catálogo de módulos, gestión de roles (crear, editar, activar/desactivar, buscar, filtrar, matriz de permisos con confirmación), gestión de usuarios (autorizar, asignar roles, activar/desactivar, eliminar conservando el historial) y auditoría.
- Módulo de Proyectos y Capacidad, fase 1 (backend): catálogo con acciones propias (liderar, aprobar, ver costos...), migraciones, Enums, Policies, servicios, API REST, historial de contratos, recargas de bolsas, periodos mensuales, flujo de aprobación de horas, costos visibles solo con permiso, plantillas de roles y datos de demostración. La matriz de permisos del panel muestra las acciones propias de cada módulo.
- Proyectos y capacidad, con pantallas: crear y editar proyectos, estados (sin cerrar con horas pendientes), cambio de líder, salud calculada, equipo (asignar, editar, terminar, eliminar), horas por semana, capacidad con festivos de Colombia, búsqueda de disponibilidad y perfiles laborales.

**Pendiente**

- El equipo ya probó el flujo con Microsoft contra su tenant (en Windows, con las pruebas del backend aprobadas). Queda repetir esa prueba con el Super Admin aprovisionado y con las plantillas de roles del módulo de proyectos.
- Pantallas de Registro de horas (mis horas, aprobaciones y tablero de rentabilidad).
- Confirmar `npm run build` con Vite en un equipo con acceso a npm (en el entorno de desarrollo se compiló con esbuild; ver «Verificación realizada»).
- Los módulos Finanzas, Inventario, Compras, Ventas, Talento humano y Reportes se retiraron del alcance y de la interfaz.
- Pruebas automatizadas del frontend (no se añadieron dependencias de testing para mantener el proyecto liviano).

## 12. Verificación realizada

El entorno donde se desarrolló no tenía acceso a Packagist ni al registro de npm. Por eso:

- **Backend:** se instalaron las dependencias reales (Laravel 13.38, Sanctum 4.3, Socialite 5.31, PHPUnit 12.5) clonando sus versiones publicadas desde GitHub. Con ellas se ejecutaron las migraciones, los comandos y **las 162 pruebas (890 aserciones), todas exitosas tanto en SQLite como en PostgreSQL 16**. `nova:demo` se ejecutó en ambas bases de datos. No se incluye `composer.lock`: se generará con `composer install`.
- **Frontend:** todo el código y los estilos se compilaron sin errores con esbuild y un compilador de Sass compatible con Dart Sass, en lugar de Vite. Con esa compilación, conectada a la API Laravel real, se revisó la interfaz en navegador (escritorio y móvil) y se ejecutó un flujo completo: redirección de rutas protegidas, creación y validación de roles, asignación de permisos con confirmación, asignación de roles, desactivación de usuarios, auditoría y cierre de sesión (cookies de sesión y CSRF reales). **`npm run build` con Vite no se pudo ejecutar en ese entorno.**
