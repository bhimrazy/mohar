#!/usr/bin/env bash
# One shared PostgreSQL for every worktree and agent, with a separate database per worktree.
#   eval "$($MOHAR_ROOT/scripts/pg.sh <slug>)"   start the shared server if needed, create
#                                                       database billing_test_<slug>, export DB_* vars
#   $MOHAR_ROOT/scripts/pg.sh --help             print the usage
#   $MOHAR_ROOT/scripts/pg.sh --stop             stop the shared server and delete its data volume
#   $MOHAR_ROOT/scripts/pg.sh --prune [slug...]  drop test databases with no open connections,
#                                                       except those of the slugs given (dropped ones are recreated on demand),
#                                                       and any idle billing_e2e_* left by an interrupted `simulate:e2e`
#   $MOHAR_ROOT/scripts/pg.sh --drop [--dry-run] <slug>  drop that slug's database, its parallel copies and seeded copies (skips any in use
#                                                       and the databases of other worktrees' slugs; --dry-run in any position)
#   $MOHAR_ROOT/scripts/pg.sh --drop-db [--dry-run] <database>  the same by database name (billing_test_*), for the sweep
#   $MOHAR_ROOT/scripts/pg.sh --list             print the test databases, one per line (parallel copies only when their base is gone)
#   $MOHAR_ROOT/scripts/pg.sh --db-name <slug>   print the database name for a slug, without starting anything
# Then run `composer test:pgsql` (or any artisan command) in the worktree's billing/ directory.
# The data lives in the Docker volume mohar-pg-data, on disk: a tmpfs is limited by the Docker VM's memory, which
# was smaller than the data. It is test data, so durability is off (no fsync, minimal WAL capped at 256 MB) and
# `--stop` deletes the volume with the server; run it if a crash leaves the server unable to start.
#
# Slug limit: PostgreSQL names are at most 63 bytes, and a parallel worker adds _test_<n> (Laravel) to the database.
# So billing_test_<slug> is kept whole while it is 55 bytes or less (a slug of up to 42 characters). A longer one
# becomes its first 46 bytes, "_", and the first 8 hex digits of its SHA-256, so it stays 55 bytes and unique.
# The container gets --shm-size=512m: Docker's 64 MB default fails the full suite with SQLSTATE[53100] "could not
# resize shared memory segment". It applies when the container is next created (after --stop or a Docker restart).
# Any slug works except one containing test_<n> (rejected); only the database name of a long slug changes. The same rule (name_fit) gives the race tests their
# extra databases (Tests\TestCase::pgsqlIdentifier, 63-byte limit).
set -euo pipefail
name=mohar-pg
port=55432
image="postgres:18.6-alpine@sha256:77f585114c32fbca283dc835b0596f4e52b51b4c6662d7810b2f4084f60a1873"

# name_fit <name> <max bytes>: the name if it fits; otherwise its first (max-9) bytes, "_", and 8 hex digits of its SHA-256.
name_fit() {
    local name="$1" max="$2" hash
    if [ "${#name}" -le "$max" ]; then
        printf '%s' "$name"
        return
    fi
    if command -v sha256sum >/dev/null 2>&1; then hash=$(printf '%s' "$name" | sha256sum); else hash=$(printf '%s' "$name" | shasum -a 256); fi
    printf '%s_%s' "${name:0:$((max - 9))}" "${hash:0:8}"
}

# db_for <slug>: the test database name for a worktree slug (55 bytes: room for _test_<n> with up to 99 workers).
db_for() {
    # test_<n> in a slug would make its database look like a parallel copy of a shorter slug's.
    if printf '%s' "$1" | grep -Eq '(^|[-_.])test[-_.][0-9]'; then
        echo "pg.sh: slug '$1' contains test_<n>; it would look like a parallel copy of another slug's database. Pick another slug." >&2
        return 1
    fi
    name_fit "billing_test_$(echo "$1" | tr -c 'a-zA-Z0-9\n' '_')" 55
}

if [ "${1:-}" = "--db-name" ]; then
    db_for "${2:?usage: pg.sh --db-name <slug>}"
    echo
    exit 0
fi

# other_bases <base database>: databases of other worktrees' slugs that extend <base> (billing_test_a is a prefix of
# billing_test_a_b), so that dropping slug "a" never reaches the idle databases of a live slug "a-b".
other_bases() {
    local base="$1" wt o common
    common="$(git -C "$(dirname "$0")" rev-parse --path-format=absolute --git-common-dir 2>/dev/null)" || return 0
    git --git-dir="$common" worktree list --porcelain 2>/dev/null | sed -n 's/^worktree //p' | while IFS= read -r wt; do
        o="$(db_for "$(basename "$wt")" 2>/dev/null)" || continue
        case "$o" in "${base}_"*) echo "$o" ;; esac
    done
}

# drop_family <base database> <dry-run: 0|1>: the database, its parallel copies (<db>_test_<n>) and the copies the tests
# derive from it (<db>[_test_<n>]_<name>, Tests\TestCase::useSeededDatabase), except those of another worktree's slug.
# Idle ones only. Returns non-zero when a drop fails.
drop_family() {
    local base="$1" dry="$2" db busy rows rc=0 skip="" o
    for o in $(other_bases "$base"); do skip="$skip AND NOT (datname = '$o' OR datname LIKE '${o}\\_%')"; done
    rows="$(docker exec "$name" psql -U billing -d billing -tAc "
        SELECT datname, EXISTS (SELECT 1 FROM pg_stat_activity a WHERE a.datname = d.datname) FROM pg_database d
        WHERE datname ~ '^${base}(_test_[0-9]+)?(_[A-Za-z0-9_]+)?\$' $skip ORDER BY 1")" || return 1
    while IFS='|' read -r db busy; do
        [ -n "$db" ] || continue
        if [ "$busy" = t ]; then echo "kept $db (open connections)"
        elif [ "$dry" = 1 ]; then echo "would drop $db"
        elif docker exec "$name" dropdb -U billing --if-exists "$db"; then echo "dropped $db"
        else echo "failed to drop $db" >&2; rc=1; fi
    done <<< "$rows"
    return $rc
}

if [ "${1:-}" = "--list" ]; then
    # Base databases, and parallel/seeded copies whose base no longer exists (orphans).
    docker exec "$name" psql -U billing -d billing -tAc "
        SELECT datname FROM pg_database d
        WHERE datname LIKE 'billing\_test\_%'
          AND (datname !~ '^billing_test_.+_test_[0-9]+(_.*)?\$'
               OR NOT EXISTS (SELECT 1 FROM pg_database b WHERE b.datname = regexp_replace(d.datname, '_test_[0-9]+(_.*)?\$', '')))
        ORDER BY 1"
    exit 0
fi

if [ "${1:-}" = "--drop" ] || [ "${1:-}" = "--drop-db" ]; then
    mode="$1"
    shift
    dry=0
    target=""
    kind="slug"; [ "$mode" = "--drop-db" ] && kind="database"
    for a in "$@"; do
        case "$a" in
            --dry-run) dry=1 ;;
            -*) echo "usage: pg.sh $mode [--dry-run] <$kind>" >&2; exit 1 ;;
            *) [ -z "$target" ] || { echo "usage: pg.sh $mode [--dry-run] <$kind>" >&2; exit 1; }; target="$a" ;;
        esac
    done
    [ -n "$target" ] || { echo "usage: pg.sh $mode [--dry-run] <$kind>" >&2; exit 1; }
    docker ps --format '{{.Names}}' | grep -qx "$name" || { echo "$name is not running; nothing to drop"; exit 0; }
    if [ "$mode" = "--drop" ]; then target="$(db_for "$target")"; fi
    [[ "$target" =~ ^billing_test_[a-zA-Z0-9_]+$ ]] || { echo "pg.sh: refusing '$target': not a billing_test_* database name" >&2; exit 1; }
    drop_family "$target" "$dry"
    exit $?
fi

if [ "${1:-}" = "--stop" ]; then
    docker rm -f "$name" >/dev/null 2>&1 || true
    docker volume rm mohar-pg-data >/dev/null 2>&1 || true
    exit 0
fi

if [ "${1:-}" = "--prune" ]; then
    shift
    keep="__none__"
    for k in "$@"; do keep="$keep|$(db_for "$k" | sed 's/^billing_test_//')"; done
    # Parallel test runs add billing_test_<slug>_test_<n>; those are kept with their slug.
    docker exec "$name" psql -U billing -d billing -tAc "
        SELECT datname FROM pg_database d
        WHERE ((datname LIKE 'billing\_test\_%' AND datname !~ '^billing_test_(${keep})(_test_[0-9]+)?$')
               OR datname ~ '^billing_e2e_[0-9a-f]{8}$')
          AND NOT EXISTS (SELECT 1 FROM pg_stat_activity a WHERE a.datname = d.datname)" |
        while read -r db; do [ -n "$db" ] && docker exec "$name" dropdb -U billing --if-exists "$db" && echo "dropped $db"; done
    exit 0
fi

usage="usage: pg.sh <worktree-slug> | --stop | --prune [slug...]"
case "${1:-}" in
    --help | -h)
        echo "$usage"
        exit 0
        ;;
    -*)
        # An unknown option would otherwise become a slug and create billing_test_<option>.
        echo "$usage" >&2
        exit 1
        ;;
esac

slug="${1:?$usage}"
db="$(db_for "$slug")"

if ! docker ps --format '{{.Names}}' | grep -qx "$name"; then
    docker rm -f "$name" >/dev/null 2>&1 || true
    docker run -d --name "$name" --shm-size=512m -p "127.0.0.1:$port:5432" -v mohar-pg-data:/var/lib/postgresql \
        -e POSTGRES_USER=billing -e POSTGRES_PASSWORD=billing -e POSTGRES_DB=billing "$image" \
        -c max_locks_per_transaction=256 -c fsync=off -c full_page_writes=off -c wal_level=minimal \
        -c max_wal_senders=0 -c max_wal_size=256MB >/dev/null
    for _ in $(seq 1 60); do docker exec "$name" pg_isready -U billing -q 2>/dev/null && break; sleep 1; done
    docker exec "$name" pg_isready -U billing -q 2>/dev/null || { echo "mohar-pg did not start; see: docker logs $name" >&2; exit 1; }
fi

docker exec "$name" psql -U billing -tAc "SELECT 1 FROM pg_database WHERE datname = '$db'" | grep -q 1 \
    || docker exec "$name" createdb -U billing "$db"

cat <<ENV
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=$port DB_DATABASE=$db DB_USERNAME=billing DB_PASSWORD=billing
ENV
