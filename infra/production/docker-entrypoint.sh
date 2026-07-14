#!/bin/bash
# PROD-PHP-RUNTIME-HARDENING: config/view caching runs here, at container
# start against the real runtime env (env_file: ./.env in
# docker-compose.yml), not baked into the image at build time -- the same
# image is deployed across environments with different .env values.
#
# Deliberately no `route:cache`: this app has 130+ closure-based routes
# (routes/web.php/auth.php), and Laravel's route cache cannot serialize
# Closures at all -- `route:cache` would fail hard on every container
# start. Converting them to controller methods to unlock route caching is
# real, separate scope (a large, dedicated refactor), not something to
# force into this pass.
set -e

php artisan config:cache
php artisan view:cache

# docker-compose.yml's `queue`/`scheduler` services override `command:`
# (e.g. `php artisan queue:work ...`) -- since this script is the image's
# ENTRYPOINT, those overrides arrive here as "$@" and must be exec'd
# directly rather than always starting nginx+php-fpm. Only the `app`
# service's default CMD (`php-fpm-and-nginx`, unmodified) takes the web
# server branch.
if [ "$1" = "php-fpm-and-nginx" ]; then
    php-fpm --nodaemonize &
    PHP_FPM_PID=$!

    nginx -g 'daemon off;' &
    NGINX_PID=$!

    term_handler() {
        kill -TERM "$PHP_FPM_PID" "$NGINX_PID" 2>/dev/null || true
        wait "$PHP_FPM_PID" "$NGINX_PID" 2>/dev/null || true
        exit 0
    }
    trap term_handler TERM INT

    wait -n "$PHP_FPM_PID" "$NGINX_PID"
    exit $?
else
    exec "$@"
fi
