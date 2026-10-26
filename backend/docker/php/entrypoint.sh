#!/bin/sh
# Arranque del contenedor del backend.
#
# Solo cuando el contenedor va a servir la API (php-fpm) se preparan la base
# de datos y las cachés. Así los comandos sueltos, por ejemplo
#   docker compose run --rm app php artisan key:generate --show
# funcionan aunque todavía no exista la configuración completa.
set -eu

cd "${APP_DIR:-/var/www/html}"

# Los volúmenes nuevos llegan vacíos: Laravel necesita estas carpetas.
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs bootstrap/cache

if [ "${1:-}" = "php-fpm" ]; then
  if [ -z "${APP_KEY:-}" ]; then
    echo "Falta APP_KEY. Genérala con: docker compose run --rm --no-deps app php artisan key:generate --show" >&2
    echo "y cópiala en el archivo .env de la raíz del proyecto." >&2
    exit 1
  fi

  if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    # La base puede tardar unos segundos más que su healthcheck en aceptar
    # conexiones de la aplicación; se reintenta antes de rendirse.
    tries=0
    until php artisan migrate --force --no-interaction; do
      tries=$((tries + 1))
      if [ "$tries" -ge 10 ]; then
        echo "No fue posible migrar la base de datos después de $tries intentos." >&2
        exit 1
      fi
      echo "La base de datos aún no responde; reintentando en 3 s ($tries/10)…" >&2
      sleep 3
    done

    # Catálogo de módulos y permisos, sedes y plantillas de roles. Ninguno
    # pisa los ajustes que el Super Admin haya hecho después.
    php artisan nova:sync-permissions --no-interaction
    php artisan nova:setup-projects --no-interaction
  fi

  # Configuración, rutas, eventos y vistas en caché (se arma con las
  # variables de este arranque).
  php artisan optimize --no-interaction
fi

exec "$@"
