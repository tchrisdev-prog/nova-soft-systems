# Imagen del backend (Laravel + PHP-FPM). La misma imagen sirve para la API
# (php-fpm) y para el programador de tareas (php artisan schedule:work).
# No lleva secretos: todo llega por variables de entorno al iniciar.

# --- Base: PHP con las extensiones que usa el proyecto --------------------
# PHP 8.4: las dependencias de Laravel 13 (Symfony 8) lo exigen.
FROM php:8.4-fpm-alpine AS base

# intl: formatos en español · pdo_pgsql: PostgreSQL · opcache: rendimiento.
# fcgi trae cgi-fcgi, que usa el healthcheck para preguntarle a PHP-FPM.
RUN apk add --no-cache icu-libs libpq fcgi \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev \
 && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql opcache \
 && apk del .build-deps

WORKDIR /var/www/html

# --- Dependencias de Composer (sin paquetes de desarrollo) ----------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache git unzip

# Primero solo composer.json (y composer.lock si existe) para aprovechar la
# caché de Docker: si no cambian, no se vuelven a descargar las dependencias.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts \
 && php artisan package:discover --ansi

# --- Imagen final ---------------------------------------------------------
FROM base AS app

COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/zz-nova.ini"
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-nova.conf
COPY docker/php/entrypoint.sh /usr/local/bin/nova-entrypoint
# Si el archivo se editó en Windows, quita los saltos de línea CRLF (sh no los entiende).
RUN tr -d '\015' < /usr/local/bin/nova-entrypoint > /tmp/entrypoint \
 && mv /tmp/entrypoint /usr/local/bin/nova-entrypoint \
 && chmod 0755 /usr/local/bin/nova-entrypoint

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html

# Laravel solo necesita escribir en storage y bootstrap/cache.
RUN chmod -R ug+rwX storage bootstrap/cache

# PHP-FPM corre sin privilegios de root.
USER www-data

EXPOSE 9000

HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=5 \
  CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong || exit 1

ENTRYPOINT ["nova-entrypoint"]
CMD ["php-fpm"]
