# Arquitectura de Nova Sync Systems

## 1. Visión general

```
Navegador (React SPA)
   │  mismo origen: /, /app/*  → archivos estáticos (Vite build)
   │                /api/*, /auth/*, /sanctum/* → Laravel
   ▼
Laravel (API REST v1)
   ├── Autenticación: Microsoft Entra ID (OIDC) → sesión Laravel (cookie HttpOnly)
   ├── Autorización: roles → permisos (<módulo>.<acción>) → módulos
   ├── Servicios de dominio + Policies + Form Requests + API Resources
   └── PostgreSQL
```

**Separación de responsabilidades**

- *Autenticación (quién es):* Microsoft Entra ID. Laravel solo valida el id_token y vincula la identidad (`oid`) con un usuario autorizado.
- *Autorización (qué puede hacer):* Nova, en el servidor, en cada petición. El frontend usa los permisos solo para adaptar la interfaz.

## 2. Decisiones técnicas

| Decisión | Motivo |
| --- | --- |
| **PostgreSQL** como base de datos principal | Integridad referencial y transacciones robustas, `SELECT … FOR UPDATE` para serializar cambios críticos (último Super Admin), tipo `uuid` nativo para los identificadores de Entra ID, JSONB para la auditoría y buen comportamiento con el crecimiento de un ERP. Las migraciones son portables: SQLite se usa en pruebas y desarrollo rápido. |
| **Sesión con cookie (Sanctum SPA)** en lugar de tokens en el navegador | El frontend nunca almacena tokens (sin exposición a XSS en `localStorage`), la cookie es HttpOnly, cifrada y con SameSite=Lax; CSRF con `XSRF-TOKEN`. Desactivar una cuenta elimina sus sesiones de inmediato. |
| **Flujo OIDC en el backend** (Authorization Code + PKCE + cliente confidencial) | El secreto queda en el servidor; el backend valida criptográficamente la identidad. No se requiere MSAL en el frontend ni permisos de Microsoft Graph. |
| **Proveedor propio sobre Laravel Socialite** | Socialite aporta `state`, PKCE y el canje del código; el proveedor `EntraIdProvider` añade `nonce` y la validación completa del id_token (`firebase/php-jwt`, dependencia de Socialite). No se agregan paquetes comunitarios. |
| **Modelo de autorización propio** (sin paquete externo) | El ERP necesita el concepto de *módulo* con estado y permisos por acción, reglas anti-escalada y protección del Super Admin. Son pocas tablas y queda bajo control del proyecto. |
| **Catálogo de módulos en `config/nova.php`** | Fuente única versionada en Git; `nova:sync-permissions` lo aplica de forma idempotente en cada despliegue. Agregar un módulo no exige cambiar la lógica de autorización. |
| **React Router (modo declarativo) + Context** | Suficiente para la escala actual sin dependencias de estado global; las páginas del panel se cargan bajo demanda (la landing no descarga código administrativo). |
| **Sass con CSS Modules** | Tokens, funciones y mixins compartidos (`styles/abstracts`), estilos con alcance por componente y sin colisiones. Fuente tipográfica autoalojada (licencia OFL). |
| **Sin librerías de UI ni de íconos** | Componentes accesibles propios (`<dialog>` nativo, checkboxes nativos, `aria-live` en notificaciones) y un set de íconos SVG coherente. Menos dependencias que mantener. |

## 3. Modelo de datos

```
users ─┐                    ┌─ permission_role ─┐
       ├─ role_user ─ roles ┤                    ├─ permissions ─ modules
       │  (assigned_by)     └────────────────────┘   (module_id, action, code)
       └─ audit_logs (actor_id, auditable_type/id, metadata)
```

| Tabla | Campos principales | Notas |
| --- | --- | --- |
| `users` | `name`, `email` (único, minúsculas), `microsoft_oid` (único), `microsoft_tenant_id`, `is_active`, `last_login_at` | Sin columna de contraseña. |
| `modules` | `code` (único), `name`, `description`, `icon`, `status` (`active`/`planned`/`inactive`), `sort_order` | Sincronizado desde `config/nova.php`. |
| `permissions` | `module_id`, `action`, `code` (único, `<módulo>.<acción>`), `description` | Único por (`module_id`, `action`). |
| `roles` | `name` (único), `slug` (único, inmutable), `description`, `is_active`, `is_system` | El slug es el identificador estable; renombrar no lo cambia. |
| `permission_role` | `role_id`, `permission_id` | Clave primaria compuesta (sin duplicados). |
| `role_user` | `role_id`, `user_id`, `assigned_by` | Clave primaria compuesta. |
| `audit_logs` | `actor_id`, `action`, `auditable_type`, `auditable_id`, `metadata`, `ip_address`, `user_agent`, `created_at` | Solo inserción. Tipos con nombre estable (`user`, `role`). |

**Permisos efectivos de un usuario** = unión de los permisos de sus roles **activos**, solo si la cuenta está **activa** y excluyendo módulos `inactive`. Un Super Admin activo tiene todos los permisos, incluidos los de módulos nuevos.

**Acceso a un módulo** = módulo `active` + al menos un permiso del usuario en ese módulo.

## 4. Reglas de seguridad de la autorización

Implementadas en `App\Services\Authorization\PrivilegeGuard` y `UserAdministrationService`, cubiertas por pruebas:

1. Cada endpoint exige su permiso específico (Policies y middleware `permission:`); ocultar un botón en React no es suficiente.
2. Un administrador que no es Super Admin **no puede**:
   - otorgar a un rol permisos que él no tiene;
   - modificar, activar o desactivar un rol que tiene asignado, ni roles con permisos superiores a los suyos;
   - asignar o retirar roles con permisos superiores a los suyos;
   - asignar, retirar o gestionar el rol Super Admin, ni gestionar a un Super Admin (aunque esté desactivado);
   - gestionar usuarios con permisos que él no tiene.
3. Nadie modifica su propio estado ni sus propios roles desde la API (evita el autobloqueo y la autoescalada).
4. El rol Super Admin es de sistema: no se renombra, no se desactiva y sus permisos no se editan.
5. Siempre debe quedar al menos un Super Admin activo; la verificación bloquea la fila del rol para evitar condiciones de carrera.
6. Los roles inactivos no se pueden asignar.
7. Asignación masiva protegida: los modelos solo aceptan atributos descriptivos; estado, identificadores y relaciones se establecen explícitamente en los servicios. En desarrollo `Model::shouldBeStrict()` detecta usos indebidos.
8. El Super Admin inicial solo se crea por consola (`nova:super-admin`), con confirmación y registro en la auditoría.
9. Eliminar una cuenta no borra la fila (eliminación lógica, `deleted_at`). Sigue las mismas reglas que desactivarla (no a uno mismo, no al último Super Admin, no a cuentas con más permisos) y además no permite eliminar a quien lidera proyectos abiertos. La cuenta pierde roles, sesiones y asignaciones abiertas; la auditoría, las horas y el historial siguen mostrando su nombre. Una cuenta eliminada no puede ingresar, ni siquiera con el registro automático activo; si se vuelve a autorizar su correo (o con `nova:super-admin`), se recupera la misma fila sin roles.

## 5. API REST v1

Base: `/api/v1`. Autenticación por cookie de sesión. Las operaciones de escritura requieren la cabecera `X-XSRF-TOKEN` (el cliente la obtiene de `/sanctum/csrf-cookie`).

| Método | Ruta | Permiso | Descripción |
| --- | --- | --- | --- |
| GET | `/auth/config` | público | Indica si el inicio de sesión con Microsoft está configurado. |
| POST | `/auth/logout` | sesión | Cierra la sesión. |
| GET | `/me` | sesión | Usuario, permisos efectivos y módulos visibles. |
| GET | `/dashboard` | sesión | Indicadores reales (cada bloque según permisos). |
| GET | `/modules` | sesión | Catálogo de módulos. |
| GET | `/permissions` | `access_control.view` | Permisos agrupados por módulo. |
| GET | `/roles` | `access_control.view` | Listado paginado (`search`, `status`, `per_page`, `page`). |
| POST | `/roles` | `access_control.create` | Crear rol. |
| GET | `/roles/{id}` | `access_control.view` | Detalle con permisos. |
| PUT | `/roles/{id}` | `access_control.update` | Editar nombre y descripción. |
| PATCH | `/roles/{id}/status` | `access_control.update` | Activar o desactivar. |
| PUT | `/roles/{id}/permissions` | `access_control.manage` | Reemplazar permisos (`permission_ids`). |
| GET | `/users` | `users.view` | Listado paginado (`search`, `status`, `role_id`). |
| POST | `/users` | `users.create` | Autorizar cuenta (`name`, `email`, `role_ids` opcional). |
| GET | `/users/{id}` | `users.view` | Detalle. |
| PATCH | `/users/{id}/status` | `users.update` | Activar o desactivar (cierra sus sesiones). |
| PUT | `/users/{id}/roles` | `users.manage` | Reemplazar roles (`role_ids`). |
| DELETE | `/users/{id}` | `users.delete` | Eliminar la cuenta: pierde el acceso y sale de las listas; su historial se conserva. |
| GET | `/audit-logs` | `audit.view` | Registro de auditoría (`action`, `page`). |

Rutas web del flujo OIDC: `GET /auth/microsoft/redirect`, `GET /auth/microsoft/callback` (limitadas a 20 solicitudes por minuto por IP). La API está limitada a 120 solicitudes por minuto por usuario.

**Respuestas**

- Éxito: `{ "data": …, "message"?: "…", "meta"?/"links"? (paginación) }`
- Error: `{ "message": "Texto para el usuario", "code": "codigo_estable", "errors"?: { "campo": ["…"] } }`

| HTTP | `code` | Uso |
| --- | --- | --- |
| 401 | `unauthenticated` | Sin sesión o sesión expirada. |
| 403 | `forbidden` | Falta el permiso requerido. |
| 403 | `account_inactive` | Cuenta desactivada (la sesión se cierra). |
| 403 | `privilege_escalation`, `protected_role`, `self_modification` | Reglas de seguridad. |
| 409 | `last_super_admin` | La operación dejaría el sistema sin Super Admin activo. |
| 409 | `user_leads_projects` | La cuenta a eliminar lidera proyectos abiertos. |
| 404 | `not_found` | Recurso inexistente. |
| 419 | `csrf_mismatch` | Token CSRF vencido (el cliente reintenta una vez). |
| 422 | `validation_failed`, `inactive_role` | Datos inválidos. |
| 429 | `too_many_requests` | Límite de solicitudes. |
| 500 | `server_error` | Error interno (sin detalles fuera de modo debug). |

## 6. Frontend

- `services/apiClient.js` centraliza `fetch` con `credentials: 'include'`, CSRF, reintento ante 419 y normalización de errores (`ApiError`).
- `context/AuthContext.jsx` carga `/me` solo cuando una ruta lo necesita, expone `can()`, `canAny()` y `canAccessModule()`, y reacciona a cualquier 401 enviando al usuario a `/login?error=session_expired`.
- `routes/ProtectedRoute.jsx` exige sesión; `RequirePermission` oculta vistas sin permiso (la API vuelve a validar).
- Estados de carga, vacío, error y éxito en todas las vistas (`StateMessage`, `SkeletonRows`, `ToastContext`).
- Confirmación explícita antes de operaciones sensibles (`ConfirmDialog`) con el detalle del cambio.
- Accesibilidad: enlace "Saltar al contenido", foco visible, `<dialog>` nativo con retorno de foco, etiquetas asociadas a cada control, tablas que se apilan en móvil con etiquetas por celda, `prefers-reduced-motion` respetado.

## 7. Despliegue

Ejemplo de Nginx con frontend y API en el mismo dominio:

```nginx
server {
    listen 443 ssl http2;
    server_name erp.tuempresa.com;

    root /var/www/nova/frontend/dist;
    index index.html;

    # API, OIDC y Sanctum → Laravel
    location ~ ^/(api|auth|sanctum)(/|$) {
        root /var/www/nova/backend/public;
        try_files $uri /index.php?$query_string;
    }

    location ~ ^/index\.php$ {
        root /var/www/nova/backend/public;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    # SPA
    location / {
        try_files $uri /index.html;
    }

    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header X-Frame-Options DENY always;
}
```

Variables de producción relevantes: `APP_URL=https://erp.tuempresa.com`, `FRONTEND_URL=https://erp.tuempresa.com`, `SANCTUM_STATEFUL_DOMAINS=erp.tuempresa.com`, `SESSION_SECURE_COOKIE=true`, `MICROSOFT_REDIRECT_URI=https://erp.tuempresa.com/auth/microsoft/callback`, `TRUSTED_PROXIES` si hay balanceador.

## 8. Módulo de Proyectos y Capacidad

Los módulos `clients`, `projects` y `timesheets` siguen las mismas reglas que el resto del sistema:
- permisos `<módulo>.<acción>`;
- Policies que nunca revisan nombres de roles;
- servicios con transacciones;
- auditoría;
- errores JSON uniformes.

Además declaran acciones propias, por ejemplo `projects.lead`, `projects.financials` o `timesheets.approve_any`.

Decisiones particulares:

| Decisión | Motivo |
| --- | --- |
| Horas en minutos y montos en pesos, como enteros | Sin errores de redondeo al sumar miles de registros. |
| Saldos y consumos calculados, no guardados | Un saldo guardado se puede desincronizar. Las sumas usan índices compuestos y responden en milisegundos con el volumen de la empresa. |
| Historial de contratos y movimientos de bolsa inmutables | Se escriben en la misma transacción que el cambio y el modelo impide editarlos o borrarlos desde la aplicación. |
| Periodos mensuales con "foto" de las condiciones | Cambiar una mensualidad no reescribe los meses ya pactados. |
| Costo por hora guardado al aprobar | La rentabilidad histórica no cambia con los aumentos de tarifa. |
| Costos solo con `projects.financials`, filtrados en la API | No basta con ocultarlos en la pantalla: para quien no tiene el permiso, los campos no existen en la respuesta. |
| Zona horaria del negocio (`America/Bogota`) separada de la del servidor (UTC) | "Hoy" y "este mes" se calculan en hora de Colombia. |
| Consultas portables | Las mismas consultas funcionan en PostgreSQL (producción) y SQLite (pruebas). Las pruebas se ejecutan en ambas. |

Detalle de datos, permisos, reglas, endpoints y datos de demostración: [projects-module.md](projects-module.md).
