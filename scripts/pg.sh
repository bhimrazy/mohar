#!/usr/bin/env bash
# One shared PostgreSQL for every worktree and agent, with a separate database per worktree.
#   eval "$($MOHAR_ROOT/scripts/pg.sh <slug>)"   start the shared server if needed, create
#                                                       database billing_test_<slug>, export DB_* vars
#   $MOHAR_ROOT/scripts/pg.sh --stop             stop the shared server
#   $MOHAR_ROOT/scripts/pg.sh --prune [slug...]  drop test databases with no open connections,
#                                                       except those of the slugs given (dropped ones are recreated on demand),
#                                                       and any idle billing_e2e_* left by an interrupted `simulate:e2e`
# Then run `composer test:pgsql` (or any artisan command) in the worktree's billing/ directory.
# The data lives in tmpfs: it is test data, fast, and disappears when the server stops.
set -euo pipefail
name=mohar-pg
port=55432
image="postgres:18.6-alpine@sha256:77f585114c32fbca283dc835b0596f4e52b51b4c6662d7810b2f4084f60a1873"

if [ "${1:-}" = "--stop" ]; then
    docker rm -f "$name" >/dev/null 2>&1 || true
    exit 0
fi

if [ "${1:-}" = "--prune" ]; then
    shift
    keep="__none__"
    for k in "$@"; do keep="$keep|$(echo "$k" | tr -c 'a-zA-Z0-9\n' '_')"; done
    # Parallel test runs add billing_test_<slug>_test_<n>; those are kept with their slug.
    docker exec "$name" psql -U billing -d billing -tAc "
        SELECT datname FROM pg_database d
        WHERE ((datname LIKE 'billing\_test\_%' AND datname !~ '^billing_test_(${keep})(_test_[0-9]+)?$')
               OR datname ~ '^billing_e2e_[0-9a-f]{8}$')
          AND NOT EXISTS (SELECT 1 FROM pg_stat_activity a WHERE a.datname = d.datname)" |
        while read -r db; do [ -n "$db" ] && docker exec "$name" dropdb -U billing --if-exists "$db" && echo "dropped $db"; done
    exit 0
fi

slug="${1:?usage: pg.sh <worktree-slug> | --stop}"
db="billing_test_$(echo "$slug" | tr -c 'a-zA-Z0-9\n' '_')"

if ! docker ps --format '{{.Names}}' | grep -qx "$name"; then
    docker rm -f "$name" >/dev/null 2>&1 || true
    docker run -d --name "$name" -p "127.0.0.1:$port:5432" --tmpfs /var/lib/postgresql:rw,size=2g \
        -e POSTGRES_USER=billing -e POSTGRES_PASSWORD=billing -e POSTGRES_DB=billing "$image" \
        -c max_locks_per_transaction=256 >/dev/null
    for _ in $(seq 1 60); do docker exec "$name" pg_isready -U billing -q 2>/dev/null && break; sleep 1; done
    docker exec "$name" pg_isready -U billing -q 2>/dev/null || { echo "mohar-pg did not start; see: docker logs $name" >&2; exit 1; }
fi

docker exec "$name" psql -U billing -tAc "SELECT 1 FROM pg_database WHERE datname = '$db'" | grep -q 1 \
    || docker exec "$name" createdb -U billing "$db"

cat <<ENV
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=$port DB_DATABASE=$db DB_USERNAME=billing DB_PASSWORD=billing
ENV
