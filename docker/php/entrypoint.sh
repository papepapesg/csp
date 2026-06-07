#!/usr/bin/env bash
set -euo pipefail

# SOPHIX BSS container entrypoint.
# Roles are selected via $CONTAINER_ROLE: app (php-fpm) | queue | scheduler | migrate.

ROLE="${CONTAINER_ROLE:-app}"
cd /var/www/html

if [ ! -f .env ]; then
    cp .env.example .env
fi

# Ensure an app key exists (idempotent).
if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    php artisan key:generate --force --no-interaction || true
fi

wait_for_db() {
    echo "[entrypoint] waiting for postgres at ${DB_HOST:-postgres}:${DB_PORT:-5432}..."
    until pg_isready -h "${DB_HOST:-postgres}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-sophix}" >/dev/null 2>&1; do
        sleep 2
    done
    echo "[entrypoint] postgres is ready."
}

case "$ROLE" in
    app)
        wait_for_db
        php artisan migrate --force --no-interaction || true
        php artisan storage:link --no-interaction || true
        php artisan config:cache || true
        php artisan route:cache || true
        exec "$@"
        ;;
    migrate)
        wait_for_db
        php artisan migrate --force --no-interaction
        php artisan db:seed --force --no-interaction || true
        exit 0
        ;;
    queue)
        wait_for_db
        exec php artisan queue:work --tries=3 --max-time=3600 --sleep=1
        ;;
    scheduler)
        wait_for_db
        exec php artisan schedule:work
        ;;
    *)
        exec "$@"
        ;;
esac
