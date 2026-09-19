FROM php:8.4-cli
RUN apt-get update && apt-get install -y git unzip libzip-dev && docker-php-ext-install pdo_mysql zip pcntl && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist
COPY . .
RUN composer dump-autoload --optimize && cp -n .env.example .env && php artisan key:generate --force
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
