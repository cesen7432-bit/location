FROM php:8.3-fpm-alpine AS app

RUN apk add --no-cache icu-libs gmp libzip unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev gmp-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath gmp intl opcache zip \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

# Dependencias primero para aprovechar la caché de capas
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]


FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/html/public
