#!/usr/bin/env bash
# Local merge gate: the steps of .github/workflows/ci.yml, run on this machine against a pull request merged into
# origin/develop. GitHub Actions is off (owner decision, 2026-10-20), so this is what "CI passed" means.
#
#   scripts/merge-gate.sh <pr-number>
#
# What it does:
#   1. A throwaway worktree at origin/develop in $GATE_ROOT/gate-<pr>, with the PR head merged in (no edits; stops
#      on a conflict). Removed on exit, pass or fail.
#   2. Dependencies from the main checkout when composer.lock / package-lock.json equal /workspaces/whmcs/billing's
#      (and its vendor/composer/installed.json matches the lock):
#      vendor/ is copied (about 270 MB in $GATE_ROOT, removed with the worktree; links break Pest and the arch
#      tests) and its autoloader regenerated; node_modules/ is a real directory of symlinks to the main checkout's
#      packages. Otherwise composer install / npm ci. .env from .env.example, key:generate, wayfinder:generate.
#   3. CI's steps in CI's order, each through scripts/heavy.sh with XDEBUG_MODE=off, stopping at the first failure:
#      postgres pin, lockfiles and audits, dependency cooldown and pinning, vp check, image sizes, Pint, tsc, Vitest,
#      PHPStan, Pest on SQLite, Pest on PostgreSQL (scripts/pg.sh gate-<pr>; the database is dropped afterwards,
#      never --prune), build, the app without dev dependencies. PHP runs at most 2 processes (shared 4 vCPUs).
#   4. A one-screen summary (step, PASS/FAIL, tests, time), also written to $GATE_ROOT/gate-<pr>.log; the full
#      output of each step stays in $GATE_ROOT/gate-<pr>.d/. Exits non-zero on failure.
#
# Merge flow (no workflow may start, so every pushed head carries [skip ci]):
#   1. scripts/merge-gate.sh <pr>                     # must end with "RESULT: PASS"
#   2. On the PR branch: git commit --allow-empty -m "chore: merge gate passed locally (<summary line>) [skip ci]"
#      and push it. The gate ran on the commit below it; nothing else may change in between.
#   3. gh pr ready <pr> && gh pr merge <pr> --merge --match-head-commit <sha of that empty commit>
#
# Settings: GATE_ROOT (default /tmp/merge-gate), GATE_PROCESSES (default 2), MAIN_CHECKOUT (/workspaces/whmcs),
# GATE_CONTINUE=1 (run every step after a failure, for a full report; the result is still FAIL).
set -uo pipefail

pr="${1:?usage: scripts/merge-gate.sh <pr-number>}"
[[ "$pr" =~ ^[0-9]+$ ]] || { echo "merge-gate: <pr-number> must be a number" >&2; exit 2; }

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
main="${MAIN_CHECKOUT:-/workspaces/whmcs}"
heavy="$repo/scripts/heavy.sh"
processes="${GATE_PROCESSES:-2}"
root="${GATE_ROOT:-/tmp/merge-gate}"
dir="$root/gate-$pr"
logs="$root/gate-$pr.d"
summary="$root/gate-$pr.log"
pg_slug="gate-$pr"
pg_db="billing_test_$(echo "$pg_slug" | tr -c 'a-zA-Z0-9\n' '_')"
pg_used=0
result=PASS
started=$(date +%s)
rows=()

export XDEBUG_MODE=off

mkdir -p "$root"
rm -rf "${logs:?}"
mkdir -p "$logs"

cleanup() {
    if [ "$pg_used" = 1 ]; then
        # This gate's database and the copies parallel tests made (<db>_test_<n>, <db>_test_<n>_<name>); nothing else.
        docker exec mohar-pg psql -U billing -d billing -tAc \
            "SELECT datname FROM pg_database WHERE datname = '$pg_db' OR datname ~ '^${pg_db}_test_[0-9]+(_.*)?$'" 2>/dev/null |
            while read -r db; do [ -n "$db" ] && docker exec mohar-pg dropdb -U billing --if-exists "$db"; done
    fi
    git -C "$repo" worktree remove --force "${dir:?}" >/dev/null 2>&1 || true
    [ -d "${dir:?}" ] && rm -rf "${dir:?}"
    git -C "$repo" worktree prune
}
trap cleanup EXIT

seconds() { local s=$1; [ "$s" -ge 60 ] && printf '%dm%02ds' $((s / 60)) $((s % 60)) || printf '%ds' "$s"; }

# Test counts from a step's output: Pest's JSON line (agent output) or its "Tests:" line, Vitest's "Tests" line.
counts() {
    local out="$1" json
    json="$(grep -o '"tool":"pest","result":"[a-z]*","tests":[0-9]*,"passed":[0-9]*[^}]*' "$out" | tail -1)"
    if [ -n "$json" ]; then
        local tests passed skipped failed
        tests=$(grep -o '"tests":[0-9]*' <<< "$json" | cut -d: -f2)
        passed=$(grep -o '"passed":[0-9]*' <<< "$json" | cut -d: -f2)
        skipped=$(grep -o '"skipped":[0-9]*' <<< "$json" | cut -d: -f2)
        failed=$(grep -o '"failed":[0-9]*' <<< "$json" | cut -d: -f2)
        printf '%s passed%s%s of %s' "$passed" "${failed:+, $failed failed}" "${skipped:+, $skipped skipped}" "$tests"
        return
    fi
    grep -E '^\s*Tests:?\s' "$out" | tail -1 | sed -E 's/^\s*Tests:?\s+//; s/\s+/ /g'
}

# step <name> <command...>: run in billing/, through heavy.sh, timed; output to $logs/<n>.log.
step() {
    local name="$1"; shift
    local n=$(( ${#rows[@]} + 1 )) out start rc
    out="$logs/$(printf '%02d' "$n")-$(tr -c 'a-zA-Z0-9\n' '-' <<< "$name" | tr -s '-' | sed 's/-$//').log"
    printf '%-44s ' "$name"
    start=$(date +%s)
    (cd "$dir/billing" && "$heavy" "$@") > "$out" 2>&1
    rc=$?
    local took; took=$(seconds $(( $(date +%s) - start )))
    local detail; detail="$(counts "$out")"
    if [ "$rc" = 0 ]; then
        printf 'PASS  %7s  %s\n' "$took" "$detail"
        rows+=("$(printf '%-44s PASS  %7s  %s' "$name" "$took" "$detail")")
        return 0
    fi
    printf 'FAIL  %7s  %s\n' "$took" "$detail"
    rows+=("$(printf '%-44s FAIL  %7s  %s' "$name" "$took" "$detail")")
    result=FAIL
    echo "--- last lines of $out:"
    tail -25 "$out" | cut -c1-200
    [ "${GATE_CONTINUE:-0}" = 1 ] && return 1
    finish
}

finish() {
    local total; total=$(seconds $(( $(date +%s) - started )))
    {
        echo "Merge gate: PR #$pr (head ${head:0:8}) merged into origin/develop (${base:0:8}), $(date -u '+%Y-%m-%d %H:%M UTC')"
        printf '%s\n' "${rows[@]}"
        echo "RESULT: $result (total $total)"
        echo "Step output: $logs/"
    } > "$summary"
    echo
    cat "$summary"
    [ "$result" = PASS ] && exit 0 || exit 1
}

# ---------------------------------------------------------------- setup
printf '%-44s ' "setup: worktree, merge, dependencies"
setup_started=$(date +%s)
head="$(gh pr view "$pr" --json headRefOid --jq .headRefOid)" || { echo FAIL; echo "gh pr view $pr failed" >&2; exit 1; }
git -C "$repo" fetch -q origin develop "pull/$pr/head" || { echo FAIL; echo "fetch failed" >&2; exit 1; }
base="$(git -C "$repo" rev-parse origin/develop)"
git -C "$repo" worktree remove --force "${dir:?}" >/dev/null 2>&1 || true
git -C "$repo" worktree add -q --detach "$dir" "$base" || { echo FAIL; exit 1; }
if ! git -C "$dir" -c user.name=merge-gate -c user.email=merge-gate@localhost merge -q --no-ff --no-edit "$head" > "$logs/00-merge.log" 2>&1; then
    echo FAIL
    rows+=("$(printf '%-44s FAIL  %7s  %s' "setup: worktree, merge, dependencies" "-" "merge conflict with origin/develop")")
    result=FAIL
    git -C "$dir" diff --name-only --diff-filter=U | sed 's/^/  conflict: /'
    finish
fi

app="$dir/billing"
mainapp="$main/billing"
deps_note=""
# The main checkout's vendor/ is reused only when it is installed from the same lock: same composer.lock, and its
# vendor/composer/installed.json lists exactly the lock's packages and versions.
vendor_matches_lock() {
    php -r '$lock = json_decode((string) file_get_contents($argv[1]), true);
        $installed = json_decode((string) @file_get_contents($argv[2]), true);
        $versions = fn (array $packages): array => array_column($packages, "version", "name");
        $want = $versions([...$lock["packages"] ?? [], ...$lock["packages-dev"] ?? []]);
        $have = $versions($installed["packages"] ?? []);
        ksort($want); ksort($have);
        exit($want !== [] && $want === $have ? 0 : 1);' "$app/composer.lock" "$mainapp/vendor/composer/installed.json"
}
if cmp -s "$app/composer.lock" "$mainapp/composer.lock" && vendor_matches_lock; then
    # A copy, not links: Pest finds the autoloader from its own resolved path and the arch plugin skips vendor code
    # only under <root>/vendor, so linked packages run the main checkout's code or get scanned as project code.
    # cp -a keeps the path-repository links (../../tools/...) relative to this tree.
    cp -a "$mainapp/vendor" "$app/vendor"
    (cd "$app" && "$HOME/.local/bin/composer" dump-autoload -q --no-interaction) >> "$logs/00-merge.log" 2>&1 \
        || { echo FAIL; tail -20 "$logs/00-merge.log"; exit 1; }
    # Guard: the app classes must come from this tree, not the main checkout.
    probe="$(cd "$app" && php -r 'require "vendor/autoload.php";
        echo dirname(realpath((new ReflectionClass(App\Providers\AppServiceProvider::class))->getFileName()), 3);')"
    [ "$probe" = "$(realpath "$app")" ] || { echo FAIL; echo "app classes load from outside the gate tree: $probe"; exit 1; }
    deps_note="vendor copied"
else
    (cd "$app" && "$heavy" "$HOME/.local/bin/composer" install -q --no-interaction --no-progress) >> "$logs/00-merge.log" 2>&1 \
        || { echo FAIL; tail -20 "$logs/00-merge.log"; exit 1; }
    deps_note="composer install"
fi
if cmp -s "$app/package-lock.json" "$mainapp/package-lock.json" && [ -d "$mainapp/node_modules" ]; then
    mkdir -p "$app/node_modules"
    for entry in "$mainapp"/node_modules/* "$mainapp"/node_modules/.bin "$mainapp"/node_modules/.package-lock.json; do
        [ -e "$entry" ] || continue
        if [ "$(basename "$entry")" = .bin ]; then cp -a "$entry" "$app/node_modules/"; else ln -s "$entry" "$app/node_modules/"; fi
    done
    deps_note="$deps_note, node_modules linked"
else
    (cd "$app" && "$heavy" npm ci --no-audit --no-fund) >> "$logs/00-merge.log" 2>&1 || { echo FAIL; tail -20 "$logs/00-merge.log"; exit 1; }
    deps_note="$deps_note, npm ci"
fi
(
    cd "$app" && cp .env.example .env && php artisan key:generate -q --no-interaction \
        && php artisan wayfinder:generate --with-form > /dev/null
) >> "$logs/00-merge.log" 2>&1 || { echo FAIL; tail -20 "$logs/00-merge.log"; exit 1; }
took=$(seconds $(( $(date +%s) - setup_started )))
printf 'PASS  %7s  %s\n' "$took" "$deps_note"
rows+=("$(printf '%-44s PASS  %7s  %s' "setup: worktree, merge, dependencies" "$took" "$deps_note")")

# PHPStan has no CLI option for its worker count: a local config caps it.
printf 'includes:\n    - phpstan.neon\nparameters:\n    parallel:\n        maximumNumberOfProcesses: %s\n' "$processes" > "$app/phpstan.gate.neon"

# Security exceptions as CI reads them: the label, then the "Security-Exception:" lines of the PR body.
if gh pr view "$pr" --json labels --jq '.labels[].name' | grep -qx security-exception; then
    DEPENDENCY_AGE_EXCEPTIONS="$(gh pr view "$pr" --json body --jq .body | grep -iE '^\s*Security-Exception:' || true)"
    export DEPENDENCY_AGE_EXCEPTIONS
fi

# ---------------------------------------------------------------- CI's steps, in CI's order
step "postgres pin matches compose.yaml" bash -c '
    pattern="postgres:[0-9.]+-alpine[0-9.]*@sha256:[0-9a-f]{64}"
    a=$(grep -Eo "$pattern" compose.yaml | sort -u); b=$(grep -Eo "$pattern" ../.github/workflows/ci.yml | sort -u)
    c=$(grep -Eo "$pattern" ../deploy/compose.prod.yaml 2>/dev/null | sort -u)
    [ -n "$a" ] && [ "$a" = "$b" ] && { [ -z "$c" ] || [ "$a" = "$c" ]; }'
step "lockfiles in sync and audits" bash -c '
    composer validate --no-check-publish && composer audit --locked && npm audit --audit-level=high --package-lock-only
    if [ -f ../docs-site/package-lock.json ]; then (cd ../docs-site && npm audit --audit-level=high --package-lock-only); fi'
step "dependency cooldown and pinning" node scripts/check-dependency-age.mjs --base=HEAD^1
step "vp check (format + lint)" npx vp check
step "image sizes" npm run images:check
step "Pint" vendor/bin/pint --parallel --max-processes="$processes" --test
step "TypeScript" npx tsc --noEmit
step "Vitest" npm test
step "PHPStan" vendor/bin/phpstan analyse --memory-limit=1G --no-progress --configuration=phpstan.gate.neon
step "Pest (SQLite)" bash -c "php artisan config:clear -q && vendor/bin/pest --parallel --processes=$processes"

eval "$("$repo/scripts/pg.sh" "$pg_slug")" || { rows+=("$(printf '%-44s FAIL  %7s  %s' "Pest (PostgreSQL)" "-" "pg.sh $pg_slug failed")"); result=FAIL; finish; }
pg_used=1
step "Pest (PostgreSQL)" env PEST_PROCESSES="$processes" composer test:pgsql
unset DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD

step "build" npm run build
# The app must run without the dev-only simulators package: a fresh --no-dev vendor/ in the worktree.
rm -rf "${app:?}/vendor"
step "app runs without dev dependencies" bash -c '
    composer install --no-dev --no-interaction --no-progress -q
    commands="$(php artisan list --raw)"
    if grep -q "^simulate:" <<< "$commands"; then echo "simulate:* commands are available without the dev dependencies"; exit 1; fi
    php -r "require \"vendor/autoload.php\"; exit(class_exists(\"Mohar\\\\Simulators\\\\SimulatorRegistry\") ? 1 : 0);"'

finish
