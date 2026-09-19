# ---------------------------------------------------------------------------
# Ravan backend — Laravel 13 on PHP-FPM 8.4.
#
# Production image: no dev dependencies, opcache on, runs as an unprivileged
# user, and *never* bakes an APP_KEY or a .env into the layer (every install
# must generate its own — see install.sh).
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

FROM php:8.4-fpm-bookworm AS runtime

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libonig-dev default-mysql-client curl; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql zip pcntl intl bcmath gd exif opcache; \
    pecl install redis; docker-php-ext-enable redis; \
    apt-get purge -y --auto-remove libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY deploy/php/php.ini /usr/local/etc/php/conf.d/zz-ravan.ini
COPY deploy/php/www.conf /usr/local/etc/php-fpm.d/zz-ravan.conf

WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY backend/ ./
# The signal catalog lives outside backend/; the seeder and the API read it from here.
COPY catalog/signal_catalog.json catalog/feature_dictionary.json /catalog/

RUN set -eux; \
    composer dump-autoload --optimize --no-dev --no-interaction; \
    rm -f .env; \
    mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache; \
    chmod -R 775 storage bootstrap/cache

COPY deploy/php/entrypoint.sh /usr/local/bin/ravan-entrypoint
RUN chmod +x /usr/local/bin/ravan-entrypoint

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=5 \
    CMD php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);'

USER www-data
EXPOSE 9000
ENTRYPOINT ["ravan-entrypoint"]
CMD ["php-fpm"]
