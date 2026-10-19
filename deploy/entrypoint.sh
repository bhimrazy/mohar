#!/bin/sh
# Container entrypoint for every service of the image (web, workers, scheduler, migrate).
#  1. Recreate the storage skeleton (storage/ is a volume that may start empty) and the per-container directory
#     for compiled views (VIEW_COMPILED_PATH, on the bootstrap/cache tmpfs).
#  2. Cache config, routes, events and views (`php artisan optimize`). config:cache embeds the environment, so it
#     runs at start, not at build; bootstrap/cache is a tmpfs on a read-only root filesystem.
#  3. Hand over to the command (frankenphp run, queue:work, schedule:work, artisan ...).
# APP_OPTIMIZE=0 skips step 2, e.g. for a one-off `php artisan` run that must not touch caches.
# Every cache written here is per container: config, routes, events, packages and services in bootstrap/cache, and
# views in VIEW_COMPILED_PATH (both tmpfs). Run the container with an init process (compose `init: true`,
# `docker run --init`) so the scheduler's background tasks are reaped.
set -eu

cd /app

mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs "${VIEW_COMPILED_PATH:-storage/framework/views}"

if [ "${APP_OPTIMIZE:-1}" = 1 ]; then
    php artisan optimize --no-interaction >&2
fi

exec "$@"
