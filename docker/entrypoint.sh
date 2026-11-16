#!/bin/sh
set -e

cd /var/www/html

# El volumen de storage puede llegar vacío
mkdir -p storage/app/public storage/app/private storage/logs storage/api-docs \
    storage/framework/cache/data storage/framework/sessions storage/framework/views

if [ "$1" = "php-fpm" ]; then
    if [ -z "$APP_KEY" ]; then
        echo "Falta APP_KEY en .env.docker (genere una con: docker compose run --rm app php artisan key:generate --show)" >&2
        exit 1
    fi

    php artisan optimize

    if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
        php artisan migrate --force
    fi

    php artisan l5-swagger:generate
fi

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
