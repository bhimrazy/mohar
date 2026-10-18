#!/usr/bin/env bash
# One shared PostgreSQL for every worktree and agent, with a separate database per worktree.
#   eval "$(/workspaces/whmcs/scripts/pg.sh <slug>)"   start the shared server if needed, create
#                                                       database billing_test_<slug>, export DB_* vars
#   /workspaces/whmcs/scripts/pg.sh --stop             stop the shared server
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

slug="${1:?usage: pg.sh <worktree-slug> | --stop}"
db="billing_test_$(echo "$slug" | tr -c 'a-zA-Z0-9\n' '_')"

if ! docker ps --format '{{.Names}}' | grep -qx "$name"; then
    docker rm -f "$name" >/dev/null 2>&1 || true
    docker run -d --name "$name" -p "127.0.0.1:$port:5432" --tmpfs /var/lib/postgresql:rw,size=2g \
        -e POSTGRES_USER=billing -e POSTGRES_PASSWORD=billing -e POSTGRES_DB=billing "$image" >/dev/null
    for _ in $(seq 1 60); do docker exec "$name" pg_isready -U billing -q 2>/dev/null && break; sleep 1; done
    docker exec "$name" pg_isready -U billing -q 2>/dev/null || { echo "mohar-pg did not start; see: docker logs $name" >&2; exit 1; }
fi

docker exec "$name" psql -U billing -tAc "SELECT 1 FROM pg_database WHERE datname = '$db'" | grep -q 1 \
    || docker exec "$name" createdb -U billing "$db"

cat <<ENV
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=$port DB_DATABASE=$db DB_USERNAME=billing DB_PASSWORD=billing
ENV
