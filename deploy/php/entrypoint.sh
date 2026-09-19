#!/bin/sh
# Ravan backend entrypoint.
#
# Waits for MySQL, then (on the `backend` role only) runs migrations and warms
# the framework caches. Every role shares this script so worker/scheduler/reverb
# containers never race the migration.
set -eu

role="${RAVAN_ROLE:-backend}"

wait_for_db() {
    echo "[entrypoint] waiting for ${DB_HOST:-mysql}:${DB_PORT:-3306} ..."
    i=0
    until php -r '
        $h = getenv("DB_HOST") ?: "mysql";
        $p = (int) (getenv("DB_PORT") ?: 3306);
        exit(@fsockopen($h, $p, $e, $s, 2) ? 0 : 1);
    ' 2>/dev/null; do
        i=$((i + 1))
        [ "$i" -gt 120 ] && { echo "[entrypoint] database never came up" >&2; exit 1; }
        sleep 2
    done
}

warm_caches() {
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
    # View cache is skipped: the API serves JSON and the SPA is static.
}

case "$role" in
    backend)
        wait_for_db
        php artisan migrate --force --no-interaction
        # Always idempotent: the signal catalog, consent texts and ICD-11 codes
        # are reference data the app cannot run without.
        php artisan db:seed --force --no-interaction
        if [ "${RAVAN_SEED:-false}" = "true" ]; then
            echo "[entrypoint] RAVAN_SEED=true — loading demo accounts and sample data"
            php artisan db:seed --force --no-interaction --class=Database\\Seeders\\DemoSeeder
        fi
        php artisan storage:link 2>/dev/null || true
        warm_caches
        ;;
    worker|scheduler|reverb)
        wait_for_db
        warm_caches
        ;;
esac

exec "$@"
