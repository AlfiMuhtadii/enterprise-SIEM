FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev postgresql-client curl ca-certificates \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && php artisan package:discover --ansi || true

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
