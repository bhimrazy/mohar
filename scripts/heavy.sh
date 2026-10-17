#!/usr/bin/env bash
# Run a CPU-heavy command (tests, phpstan, pint, builds, browsers) with at most HEAVY_SLOTS
# such commands running machine-wide, at low CPU/IO priority. Waits for a free slot.
#   /workspaces/whmcs/scripts/heavy.sh php artisan test --compact tests/Feature/Billing
set -uo pipefail
slots="${HEAVY_SLOTS:-2}"
lockdir="${HEAVY_LOCK_DIR:-/tmp/heavy-slots}"
mkdir -p "$lockdir"
while true; do
    for i in $(seq 1 "$slots"); do
        exec 9>"$lockdir/slot-$i.lock"
        if flock -n 9; then
            nice -n 15 ionice -c3 "$@"
            rc=$?
            flock -u 9
            exit "$rc"
        fi
        exec 9>&-
    done
    sleep 3
done
