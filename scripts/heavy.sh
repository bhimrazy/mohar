#!/usr/bin/env bash
# Run a CPU-heavy command (tests, phpstan, pint, builds, browsers) with at most HEAVY_SLOTS
# such commands running machine-wide, at low CPU/IO priority. Waits for a free slot.
#   $MOHAR_ROOT/scripts/heavy.sh php artisan test --compact tests/Feature/Billing
# Linux uses flock; macOS (no flock) uses a mkdir lock holding the owner's PID, freed when that PID is gone.
set -uo pipefail
slots="${HEAVY_SLOTS:-3}"
lockdir="${HEAVY_LOCK_DIR:-/tmp/heavy-slots}"
mkdir -p "$lockdir"

low_priority() {
    if command -v ionice >/dev/null 2>&1; then
        nice -n 15 ionice -c3 "$@"
    else
        nice -n 15 "$@"
    fi
}

if command -v flock >/dev/null 2>&1; then
    while true; do
        for i in $(seq 1 "$slots"); do
            exec 9>"$lockdir/slot-$i.lock"
            if flock -n 9; then
                low_priority "$@"
                rc=$?
                flock -u 9
                exit "$rc"
            fi
            exec 9>&-
        done
        sleep 3
    done
fi

while true; do
    for i in $(seq 1 "$slots"); do
        slot="$lockdir/slot-$i.d"
        if ! mkdir "$slot" 2>/dev/null; then
            owner="$(cat "$slot/pid" 2>/dev/null)"
            # A slot whose owner died is stale; a slot without a pid file yet is still being taken.
            if [ -n "$owner" ] && ! kill -0 "$owner" 2>/dev/null; then rm -rf "$slot"; fi
            continue
        fi
        echo $$ >"$slot/pid"
        trap 'rm -rf "$slot"' EXIT
        trap 'exit 130' INT TERM
        low_priority "$@"
        exit $?
    done
    sleep 3
done
