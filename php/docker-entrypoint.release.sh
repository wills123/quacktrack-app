#!/bin/sh
# Entrypoint for the immutable release image.
#
# Differences from docker-entrypoint.sh (dev):
#  - never runs composer install (dependencies are baked into the image)
#  - never migrates on start unless RUN_MIGRATIONS_ON_START=1. In a blue/green
#    release two stacks start against one database, so migrations are an
#    explicit release step (docker run --rm <image> php app/run_migrations.php)
#  - passes through any command, so the same image can run one-off tasks
set -e

wait_for_db() {
    [ -n "${DB_HOST:-}" ] || return 0
    max_attempts=30
    attempt=0
    while [ "$attempt" -lt "$max_attempts" ]; do
        if php -r 'try { $o = []; if (getenv("DB_SSL_CA")) { $o[PDO::MYSQL_ATTR_SSL_CA] = getenv("DB_SSL_CA"); $o[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true; } new PDO("mysql:host=" . getenv("DB_HOST") . ";port=3306", getenv("DB_USER"), getenv("DB_PASSWORD"), $o); } catch (Throwable $e) { exit(1); }'; then
            return 0
        fi
        attempt=$((attempt + 1))
        echo "Database not ready, waiting... (attempt $attempt/$max_attempts)"
        sleep 2
    done
    echo "Database not reachable after ${max_attempts} attempts" >&2
    return 1
}

if [ "$#" -gt 0 ] && [ "$1" != "php-fpm" ]; then
    exec "$@"
fi

if [ "${RUN_MIGRATIONS_ON_START:-0}" = "1" ]; then
    wait_for_db
    php /var/www/html/app/run_migrations.php
fi

exec php-fpm
