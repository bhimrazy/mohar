#!/usr/bin/env bash
# Run a command in Linux, for tests that need Linux tools (the simulators use setsid, which macOS lacks):
#   cd <worktree>/billing && $MOHAR_ROOT/scripts/linux.sh php artisan test --compact tools/simulators/tests
# Mounts the current worktree and runs in the same relative directory, on the production base image (deploy/Dockerfile).
# --init reaps the processes the simulator tests start and forwards signals to them.
set -euo pipefail
image="dunglas/frankenphp:1.13.2-php8.4-trixie@sha256:0de5a32944f72c13b45d183e8e6c53546b8172cec21cc8599e24b12e0661506d"
top="$(git rev-parse --show-toplevel)"
exec docker run --rm --init -v "$top":/app -w "/app${PWD#"$top"}" -e XDEBUG_MODE=off -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: \
    "$image" "$@"
