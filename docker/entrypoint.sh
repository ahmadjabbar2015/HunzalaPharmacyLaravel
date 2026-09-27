#!/bin/sh
# Container entrypoint for every PHP role (fpm, queue worker, scheduler).
#
# Anything that must happen before the app serves its first request belongs
# here rather than in a deploy runbook a human can forget.
set -e

role="${CONTAINER_ROLE:-app}"

# MySQL in its own container is reachable before it is ready, and a migration
# against a half-started server fails in ways that look like a code bug.
wait_for_database() {
    echo "[entrypoint] waiting for ${DB_HOST}:${DB_PORT:-3306} ..."

    i=0
    until mysqladmin ping \
            --host="${DB_HOST}" \
            --port="${DB_PORT:-3306}" \
            --user="${DB_USERNAME}" \
            --password="${DB_PASSWORD}" \
            --silent 2>/dev/null; do
        i=$((i + 1))
        if [ "$i" -ge 60 ]; then
            echo "[entrypoint] database never became ready" >&2
            exit 1
        fi
        sleep 2
    done

    echo "[entrypoint] database is up"
}

wait_for_database

if [ "$role" = "app" ]; then
    # Only the app container migrates. If the worker and scheduler did it too,
    # three containers would race on the same schema at every boot.
    echo "[entrypoint] running migrations"
    php artisan migrate --force --isolated

    # --isolated takes a lock, so a rolling restart of several app containers
    # still runs migrations exactly once.

    # Caches are built at boot, not baked into the image: they capture .env,
    # which is supplied at runtime. Baking them would freeze build-time config
    # into every deployment.
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache

    # Receipt logos and any uploaded asset are served from public/storage.
    php artisan storage:link --force 2>/dev/null || true
fi

if [ "$role" = "queue" ]; then
    php artisan config:cache
    echo "[entrypoint] starting queue worker"
    # --max-time recycles the worker hourly so a leak cannot accumulate, and
    # so a worker still holding stale code after a deploy is replaced.
    exec php artisan queue:work \
        --tries=3 \
        --max-time=3600 \
        --sleep=3 \
        --backoff=10
fi

if [ "$role" = "scheduler" ]; then
    php artisan config:cache
    echo "[entrypoint] starting scheduler"
    # schedule:work is a foreground loop, which is what a container wants -
    # no cron daemon, no second process to supervise.
    exec php artisan schedule:work
fi

exec "$@"
