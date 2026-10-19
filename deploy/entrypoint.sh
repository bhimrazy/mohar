#!/bin/sh
# Container entrypoint for every service of the image (web, workers, scheduler, migrate).
#  1. Recreate the storage skeleton: storage/ is a volume that may start empty.
#  2. Cache config, routes, events and views (`php artisan optimize`). config:cache embeds the environment, so it
#     runs at start, not at build; bootstrap/cache is a tmpfs on a read-only root filesystem.
#  3. Hand over to the command (frankenphp run, queue:work, schedule:work, artisan ...).
# APP_OPTIMIZE=0 skips step 2, e.g. for a one-off `php artisan` run that must not touch caches.
set -eu

cd /app

mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs

if [ "${APP_OPTIMIZE:-1}" = 1 ]; then
    php artisan optimize --no-interaction >&2
fi

exec "$@"
