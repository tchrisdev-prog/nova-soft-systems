# Nova Sync Systems en Docker

Con Docker el ERP corre completo en cuatro contenedores, sin instalar PHP, Composer, Node ni PostgreSQL en el equipo:

| Servicio | Imagen | Qué hace |
| --- | --- | --- |
| `web` | nginx 1.27 + la interfaz compilada | Sirve React y reenvía `/api`, `/auth`, `/sanctum` y `/up` a Laravel. Es el único puerto publicado (8080). |
| `app` | PHP 8.4-FPM + Laravel | La API. Al arrancar aplica migraciones, sincroniza módulos y permisos, crea sedes y plantillas de roles (sin pisar ajustes) y arma las cachés. |
| `scheduler` | la misma del backend | `php artisan schedule:work`: abre cada mes de las mensualidades. |
| `db` | PostgreSQL 16 | Datos en el volumen `pgdata`. No se publica fuera de Docker. |

El navegador ve un solo origen (`http://localhost:8080`): la cookie de sesión y el CSRF de Sanctum funcionan sin CORS, igual que con el proxy de Vite en desarrollo.

## Requisitos

- Docker Desktop (Windows o macOS) o Docker Engine con el plugin Compose v2.
- El registro de la aplicación en Entra ID (ver `docs/entra-id-setup.md`), con este URI de redirección **adicional**:
  `http://localhost:8080/auth/microsoft/callback`
  Entra ID acepta `http://` solo para `localhost`; en un servidor real debe ser `https://`.

## Primer arranque

En PowerShell, desde la carpeta raíz del proyecto (donde está `docker-compose.yml`):

```powershell
Copy-Item .env.docker.example .env
notepad .env        # pon una contraseña en DB_PASSWORD y los datos de Microsoft

# Genera la clave de la aplicación y pégala en APP_KEY dentro de .env
docker compose run --rm --no-deps app php artisan key:generate --show

docker compose up -d --build
docker compose ps   # espera a que app y web digan "healthy"
```

Abre `http://localhost:8080`.

**Primer Super Admin** (la cuenta de Microsoft 365 que administrará el ERP):

```powershell
docker compose exec app php artisan nova:super-admin tu.correo@empresa.com
```

**Datos de demostración** (solo con `APP_ENV=local`; en `production` el comando se niega):

```powershell
docker compose exec app php artisan nova:demo --presenter=tu.correo@empresa.com
```

## Uso diario

| Para | Comando |
| --- | --- |
| Ver los registros | `docker compose logs -f app web` |
| Detener (los datos se conservan) | `docker compose down` |
| Volver a levantar | `docker compose up -d` |
| Actualizar tras cambiar el código | `docker compose up -d --build` (las migraciones nuevas se aplican solas) |
| Un comando de Laravel | `docker compose exec app php artisan <comando>` |
| Copia de seguridad de la base | `docker compose exec db pg_dump -U nova nova_sync > respaldo.sql` |
| Restaurar una copia | `Get-Content respaldo.sql \| docker compose exec -T db psql -U nova nova_sync` |
| Borrar todo, incluidos los datos | `docker compose down -v` (irreversible) |

Si cambiaste `DB_USERNAME` o `DB_DATABASE` en `.env`, usa esos valores en los comandos de copia.

## Seguridad

- **Secretos:** ningún secreto va dentro de las imágenes. `APP_KEY`, la contraseña de la base y el secreto de Microsoft llegan por variables de entorno desde `.env`, que está en `.gitignore`. Si falta `DB_PASSWORD`, Compose no arranca; si falta `APP_KEY`, el contenedor `app` se detiene y explica cómo generarla.
- **Usuarios sin privilegios:** PHP-FPM corre como `www-data`, no como root.
- **Red interna:** solo `web` publica un puerto; PHP-FPM (9000) y PostgreSQL (5432) quedan dentro de la red de Docker.
- **Producción en PHP:** sin `display_errors`, sin la cabecera `X-Powered-By` y con OPcache.
- **Cabeceras de nginx:** `Content-Security-Policy` (solo recursos propios), `X-Frame-Options: DENY`, `X-Content-Type-Options`, `Referrer-Policy` y `Permissions-Policy`. También se ocultan la versión de nginx y los archivos ocultos.
- **Healthchecks:** `app` responde al ping de PHP-FPM y `web` a `/healthz`. Compose espera a que la base y la API estén sanas antes de abrir la web.

## En un servidor real

1. `APP_ENV=production` en `.env` (bloquea la demo y activa las protecciones de producción).
2. Un nombre de dominio con HTTPS: un proxy con TLS delante del puerto 8080 (Caddy, Traefik, un balanceador de la nube, Azure Front Door...). Entonces:
   - `APP_URL=https://erp.tuempresa.com`
   - `SANCTUM_STATEFUL_DOMAINS=erp.tuempresa.com`
   - `SESSION_SECURE_COOKIE=true`
   - `MICROSOFT_REDIRECT_URI=https://erp.tuempresa.com/auth/microsoft/callback`, registrado igual en Entra ID
   - `TRUSTED_PROXIES` con la IP o red del proxy
   - Activa HSTS en ese proxy.
3. Guarda los secretos en el gestor de la plataforma (Azure Key Vault, variables protegidas del servidor...) en lugar de un `.env` a mano, y respalda el volumen `pgdata` con frecuencia.
4. Para versiones reproducibles, genera y sube `backend/composer.lock` y `frontend/package-lock.json` (`composer install` y `npm install` una vez en tu equipo): las imágenes los usan si existen.

## Qué se verificó

- `docker compose config` valida el archivo, incluido el error claro cuando falta `DB_PASSWORD`.
- El arranque de `app` se probó fuera de Docker con el mismo script, solo con variables de entorno, sin `.env`, contra PostgreSQL 16:
  - sin `APP_KEY` se detiene con el mensaje esperado;
  - `key:generate --show` funciona antes de configurar;
  - con la clave, migra, sincroniza permisos, crea sedes y roles y arma las cachés;
  - después, la API respondió en `/up`, `/api/v1/auth/config` y `/sanctum/csrf-cookie` (cookie de sesión `HttpOnly`, `SameSite=Lax`);
  - `nova:demo` cargó los datos.
- **No se pudieron construir las imágenes** en el entorno de desarrollo, porque no tenía acceso a Docker Hub. La primera ejecución de `docker compose up --build` en tu equipo es la prueba real del Dockerfile y de la configuración de nginx.
