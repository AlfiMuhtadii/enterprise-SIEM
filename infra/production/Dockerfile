# PROD-IMAGE-HERMETIC-ASSETS: Vite assets are built inside the image from
# a lockfile (npm ci), not assumed to already exist in public/build from a
# prior host-side `npm run build` -- a clean checkout builds correctly.
FROM node:20-slim AS node-build
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# Composer dependencies, isolated from the app source so this layer only
# rebuilds when composer.json/composer.lock actually change.
FROM composer:2 AS composer-build
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --no-progress

# PROD-PHP-RUNTIME-HARDENING: php:8.3-fpm-bookworm + nginx replaces
# `php artisan serve` (Laravel's local development server, single-threaded,
# not intended for production traffic) as the production process model.
# Non-root, OPcache enabled, only `public/` is web-served.
FROM php:8.3-fpm-bookworm AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx libpq-dev postgresql-client ca-certificates curl \
    && docker-php-ext-install pdo pdo_pgsql opcache \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default

RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        # Baked once at image-build layer time; validate_timestamps=0 means
        # a code change requires a new image/container, which matches this
        # project's immutable-image deployment model -- never edited in place.
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache-prod.ini

COPY infra/production/nginx.conf /etc/nginx/nginx.conf
COPY infra/production/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www/html
COPY . .
COPY --from=composer-build /build/vendor ./vendor
COPY --from=node-build /build/public/build ./public/build

RUN php artisan package:discover --ansi

# The stock php:*-fpm image's default pool (www.conf) already runs FPM
# workers as www-data (uid 33) -- reused as-is rather than adding a custom
# pool file. nginx's `user` directive is intentionally omitted from
# nginx.conf: the master process itself runs as www-data (no root step to
# drop from), which is only possible because this listens on the
# unprivileged port 8000, not 80.
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && mkdir -p /var/log/nginx /var/lib/nginx/body /run \
    && chown -R www-data:www-data /var/log/nginx /var/lib/nginx /run \
    && touch /run/nginx.pid && chown www-data:www-data /run/nginx.pid

USER www-data

EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8000/health/ready || exit 1

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["php-fpm-and-nginx"]
