#!/usr/bin/env bash
# Local merge gate: the steps of .github/workflows/ci.yml's main job, run on this machine against a pull request
# merged into origin/develop. For when Actions is unavailable (private repository, minutes used up).
# Runs on macOS (bash 3.2, BSD tools) and Linux.
#
#   scripts/merge-gate.sh <pr-number>
#
# What it does:
#   1. A throwaway worktree at origin/develop in $GATE_ROOT/gate-<pr>, with the PR head merged in (no edits; stops
#      on a conflict). Removed on exit, pass or fail. A PR already in develop gates develop itself.
#   2. Dependencies from the main checkout when composer.lock / package-lock.json equal its billing/ ones (and its
#      vendor/composer/installed.json matches the lock): vendor/ is copied (links break Pest and the arch tests) and
#      its autoloader regenerated; node_modules/ is a directory of symlinks to the main checkout's packages.
#      Otherwise composer install / npm ci. .env from .env.example, key:generate, wayfinder:generate.
#   3. CI's steps in CI's order, each through scripts/heavy.sh with XDEBUG_MODE=off, stopping at the first failure.
#      CI's change detection decides which steps run: a docs-only change takes the fast path (vp check, image sizes),
#      the audits run only when a manifest, lockfile, .npmrc or workflow file changed. PHP runs at most
#      $GATE_PROCESSES processes. Pest on PostgreSQL uses scripts/pg.sh gate-<pr>; the gate drops only its own
#      databases (never pg.sh --prune). On macOS the Linux-only tests (simulators, deploy script probes) are left out
#      of the native runs and run once in the production base image through scripts/linux.sh (SQLite).
#   4. A one-screen summary (step, PASS/FAIL/SKIP, tests, time), also written to $GATE_ROOT/gate-<pr>.log; the full
#      output of each step stays in $GATE_ROOT/gate-<pr>.d/. Exits non-zero on failure.
#
# The gate executes the PR's code (tests, composer and npm scripts) with your user's environment: run it only on a
# PR whose diff you have read. PRs from forks are refused unless GATE_ALLOW_FORK=1.
#
# Merge flow:
#   1. scripts/merge-gate.sh <pr>                     # must end with "RESULT: PASS"
#   2. gh pr comment <pr> --body-file $GATE_ROOT/gate-<pr>.log
#   3. gh pr ready <pr> && gh pr merge <pr> --merge --match-head-commit <head sha from the summary>
#      (--match-head-commit refuses the merge if anything was pushed after the gated head)
#
# Settings: GATE_ROOT (default <main checkout>/.worktrees/.merge-gate), GATE_PROCESSES (default 2), MAIN_CHECKOUT
# (default: the repository's main worktree), GATE_SCRIPTS (directory holding heavy.sh, pg.sh and linux.sh; default
# this script's directory), GATE_CONTINUE=1 (run every step after a failure; the result is still FAIL).
set -uo pipefail

pr="${1:?usage: scripts/merge-gate.sh <pr-number>}"
[[ "$pr" =~ ^[0-9]+$ ]] || { echo "merge-gate: <pr-number> must be a number" >&2; exit 2; }

repo="$(cd "$(dirname "$0")/.." && pwd)"
scripts="${GATE_SCRIPTS:-$(cd "$(dirname "$0")" && pwd)}"
common="$(git -C "$repo" rev-parse --path-format=absolute --git-common-dir)" || exit 1
main="${MAIN_CHECKOUT:-$(dirname "$common")}"
heavy="$scripts/heavy.sh"
processes="${GATE_PROCESSES:-2}"
root="${GATE_ROOT:-$main/.worktrees/.merge-gate}"
dir="$root/gate-$pr"
logs="$root/gate-$pr.d"
summary="$root/gate-$pr.log"
pg_slug="gate-$pr"
pg_db="billing_test_$(echo "$pg_slug" | tr -c 'a-zA-Z0-9\n' '_')"
pg_used=0
worktree_added=0
result=PASS
started=$(date +%s)
os="$(uname -s)"
rows=()
head=""
base=""

export XDEBUG_MODE=off

# Preconditions, before anything is created.
fail_early() { echo "merge-gate: $*" >&2; exit 1; }
[ -x "$heavy" ] || fail_early "$heavy not found (set GATE_SCRIPTS)"
if ! command -v flock > /dev/null 2>&1 && ! grep -q mkdir "$heavy"; then
    fail_early "$heavy needs flock, which this machine lacks; point GATE_SCRIPTS at a scripts/ with the mkdir-lock heavy.sh (#271)"
fi
if [ "$os" = Darwin ] && [ ! -x "$scripts/linux.sh" ]; then
    fail_early "$scripts/linux.sh not found; it runs the Linux-only simulator suite on macOS (set GATE_SCRIPTS, #271)"
fi
php_version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2> /dev/null)"
[ "$php_version" = 8.4 ] || fail_early "PHP 8.4 required on PATH (CI's version), found '${php_version:-none}'"
command -v composer > /dev/null 2>&1 || fail_early "composer not on PATH"
command -v docker > /dev/null 2>&1 || fail_early "docker not on PATH (PostgreSQL runs in mohar-pg)"
[ "$(gh pr view "$pr" --json isCrossRepository --jq .isCrossRepository 2> /dev/null)" = false ] || [ "${GATE_ALLOW_FORK:-0}" = 1 ] \
    || fail_early "PR #$pr is from a fork (or gh pr view failed); read its diff, then rerun with GATE_ALLOW_FORK=1"

mkdir -p "$root"
rm -rf "${logs:?}"
mkdir -p "$logs"

cleanup() {
    if [ "$pg_used" = 1 ]; then
        # This gate's database and the copies parallel tests made (<db>_test_<n>, <db>_test_<n>_<name>); nothing else.
        docker exec mohar-pg psql -U billing -d billing -tAc \
            "SELECT datname FROM pg_database WHERE datname = '$pg_db' OR datname ~ '^${pg_db}_test_[0-9]+(_.*)?$'" 2> /dev/null |
            while read -r db; do [ -n "$db" ] && docker exec mohar-pg dropdb -U billing --if-exists "$db"; done
    fi
    if [ "$worktree_added" = 1 ]; then
        git -C "$repo" worktree remove --force "${dir:?}" > /dev/null 2>&1 || true
        [ -d "${dir:?}" ] && rm -rf "${dir:?}"
        git -C "$repo" worktree prune
    fi
}
trap cleanup EXIT
trap 'exit 130' INT TERM

seconds() { local s=$1; if [ "$s" -ge 60 ]; then printf '%dm%02ds' $((s / 60)) $((s % 60)); else printf '%ds' "$s"; fi; }
row() { rows+=("$(printf '%-44s %-4s  %7s  %s' "$1" "$2" "$3" "$4")"); printf '%-4s  %7s  %s\n' "$2" "$3" "$4"; }

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
    # [[:space:]], not \s: BSD sed reads \s as a literal s.
    grep -E '^[[:space:]]*Tests:?[[:space:]]' "$out" | tail -1 | sed -E 's/^[[:space:]]*Tests:?[[:space:]]+//; s/[[:space:]]+/ /g'
}

# step <name> <command...>: run in billing/, through heavy.sh, timed; output to $logs/<n>.log.
step() {
    local name="$1"; shift
    local n=$(( ${#rows[@]} + 1 )) out start rc took detail
    out="$logs/$(printf '%02d' "$n")-$(tr -c 'a-zA-Z0-9\n' '-' <<< "$name" | tr -s '-' | sed 's/-$//').log"
    printf '%-44s ' "$name"
    start=$(date +%s)
    (cd "$dir/billing" && "$heavy" "$@") > "$out" 2>&1
    rc=$?
    took=$(seconds $(( $(date +%s) - start )))
    detail="$(counts "$out")"
    if [ "$rc" = 0 ]; then row "$name" PASS "$took" "$detail"; return 0; fi
    row "$name" FAIL "$took" "$detail"
    result=FAIL
    echo "--- last lines of $out:"
    tail -25 "$out" | cut -c1-200
    [ "${GATE_CONTINUE:-0}" = 1 ] && return 1
    finish
}

# when <true|false> <name> <command...>: CI's `if: steps.changes.outputs.<x> == 'true'`.
when() {
    local cond="$1"; shift
    if [ "$cond" = true ]; then step "$@"; else printf '%-44s ' "$1"; row "$1" SKIP - "not run by CI for this change"; fi
}

finish() {
    local total; total=$(seconds $(( $(date +%s) - started )))
    {
        echo "Merge gate: PR #$pr (head ${head:0:8}) merged into origin/develop (${base:0:8}) on $os, $(date -u '+%Y-%m-%d %H:%M UTC')"
        printf '%s\n' ${rows[@]+"${rows[@]}"}
        echo "RESULT: $result (total $total)"
        echo "Head: $head"
        echo "Step output: $logs/"
    } > "$summary"
    echo
    cat "$summary"
    [ "$result" = PASS ] && exit 0 || exit 1
}

# ---------------------------------------------------------------- setup
printf '%-44s ' "setup: worktree, merge, dependencies"
setup_started=$(date +%s)
setup_fail() { row "setup: worktree, merge, dependencies" FAIL - "$1"; result=FAIL; [ -f "$logs/00-setup.log" ] && tail -20 "$logs/00-setup.log"; finish; }
head="$(gh pr view "$pr" --json headRefOid --jq .headRefOid)" || setup_fail "gh pr view $pr failed"
git -C "$repo" fetch -q origin develop "pull/$pr/head" || setup_fail "fetch failed"
base="$(git -C "$repo" rev-parse origin/develop)"
git -C "$repo" worktree add -q --detach "$dir" "$base" > "$logs/00-setup.log" 2>&1 || setup_fail "worktree add failed ($dir exists?)"
worktree_added=1
merged_already=0
if git -C "$dir" merge-base --is-ancestor "$head" "$base"; then
    merged_already=1
elif ! git -C "$dir" -c user.name=merge-gate -c user.email=merge-gate@localhost merge -q --no-ff --no-edit "$head" >> "$logs/00-setup.log" 2>&1; then
    git -C "$dir" diff --name-only --diff-filter=U | sed 's/^/  conflict: /'
    setup_fail "merge conflict with origin/develop"
fi

# CI's "Detect docs-only change and diff base" step, for a pull_request event (the diff of the merge commit).
code=true; deps=false; audit=true; dep_base=HEAD^1; files=""
if [ "$merged_already" = 1 ]; then
    dep_base=--all # like a push without a usable "before": every step, every dependency
else
    files="$(git -C "$dir" diff --name-only HEAD^1 HEAD)"
fi
docs_re='(\.md$|\.png$|^billing/docs/|^docs-site/)'
site_deps_re='^docs-site/(package\.json|package-lock\.json|\.npmrc|\.nvmrc)$'
audit_re='^(billing/(composer\.(json|lock)|package(-lock)?\.json)|docs-site/package(-lock)?\.json|(.*/)?\.npmrc|\.github/workflows/.+)$'
if [ -n "$files" ] && [ -z "$(grep -vE "$docs_re" <<< "$files")" ]; then
    code=false
    grep -qE "$site_deps_re" <<< "$files" && deps=true
fi
if [ -n "$files" ]; then
    if grep -qE "$audit_re" <<< "$files"; then audit=true; else audit=false; fi
fi
policy=false; { [ "$code" = true ] || [ "$deps" = true ]; } && policy=true

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
    # cp -a keeps the path-repository links (../../tools/...) relative to this tree (GNU and BSD cp alike).
    cp -a "$mainapp/vendor" "$app/vendor"
    (cd "$app" && composer dump-autoload -q --no-interaction) >> "$logs/00-setup.log" 2>&1 || setup_fail "composer dump-autoload failed"
    # Guard: the app classes must come from this tree, not the main checkout.
    probe="$(cd "$app" && php -r 'require "vendor/autoload.php";
        echo dirname(realpath((new ReflectionClass(App\Providers\AppServiceProvider::class))->getFileName()), 3);')"
    [ "$probe" = "$(cd "$app" && pwd -P)" ] || setup_fail "app classes load from outside the gate tree: $probe"
    deps_note="vendor copied"
else
    (cd "$app" && "$heavy" composer install -q --no-interaction --no-progress) >> "$logs/00-setup.log" 2>&1 || setup_fail "composer install failed"
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
    (cd "$app" && "$heavy" npm ci --no-audit --no-fund) >> "$logs/00-setup.log" 2>&1 || setup_fail "npm ci failed"
    deps_note="$deps_note, npm ci"
fi
(
    cd "$app" && cp .env.example .env && php artisan key:generate -q --no-interaction \
        && php artisan wayfinder:generate --with-form > /dev/null
) >> "$logs/00-setup.log" 2>&1 || setup_fail "key:generate / wayfinder:generate failed"
node_have="$(node --version 2> /dev/null | sed 's/^v//')"
node_want="$(tr -d '[:space:]v' < "$app/.nvmrc")"
[ "$node_have" = "$node_want" ] || deps_note="$deps_note, node $node_have (CI: $node_want)"
[ "$merged_already" = 1 ] && deps_note="$deps_note, already in develop"
[ "$code" = false ] && deps_note="$deps_note, docs-only fast path"
row "setup: worktree, merge, dependencies" PASS "$(seconds $(( $(date +%s) - setup_started )))" "$deps_note"

# PHPStan has no CLI option for its worker count: a local config caps it.
printf 'includes:\n    - phpstan.neon\nparameters:\n    parallel:\n        maximumNumberOfProcesses: %s\n' "$processes" > "$app/phpstan.gate.neon"

# Security exceptions as CI reads them: the label, then the "Security-Exception:" lines of the PR body.
if gh pr view "$pr" --json labels --jq '.labels[].name' | grep -qx security-exception; then
    DEPENDENCY_AGE_EXCEPTIONS="$(gh pr view "$pr" --json body --jq .body | grep -iE '^[[:space:]]*Security-Exception:' || true)"
    export DEPENDENCY_AGE_EXCEPTIONS
fi

# On macOS some tests need Linux: the simulators (setsid, /proc) and the deploy script probes (GNU realpath -m;
# production runs deploy/bin/deploy on Linux). A gate-only phpunit config moves them into a "Linux only" suite that the
# native runs exclude and scripts/linux.sh runs once in the production base image.
linux_only_suite="Linux only"
# The deploy probes are found, not listed, so a new one is never run natively: every test that runs a deploy/ or
# loadtest/ script through bash or sh.
linux_only_files=()
while IFS= read -r f; do linux_only_files+=("$f"); done < <(cd "$app" && grep -rlE "\['(ba)?sh', [^]]*(deploy|loadtest)/" tests | sort)
linux_only_dirs=(tools/simulators/tests)
sqlite_args=(--parallel --processes="$processes")
pgsql_args=(--parallel --processes="$processes" "--exclude-testsuite=Unit,Arch")
if [ "$os" = Darwin ]; then
    (cd "$app" && php -r '
        [$_, $suite] = $argv; $files = array_filter(explode(",", $argv[2])); $dirs = array_filter(explode(",", $argv[3]));
        $doc = new DOMDocument(); $doc->preserveWhiteSpace = false; $doc->load("phpunit.xml");
        $suites = $doc->getElementsByTagName("testsuites")->item(0);
        $linux = $doc->createElement("testsuite"); $linux->setAttribute("name", $suite);
        foreach ($files as $f) { $linux->appendChild($doc->createElement("file", $f)); }
        foreach ($dirs as $d) { $linux->appendChild($doc->createElement("directory", $d)); }
        $found = [];
        foreach (iterator_to_array($suites->getElementsByTagName("testsuite")) as $ts) {
            foreach (iterator_to_array($ts->getElementsByTagName("directory")) as $dir) {
                if (in_array($dir->textContent, $dirs, true)) { $found[] = $dir->textContent; $suites->removeChild($ts); continue 2; }
                foreach ($files as $f) {
                    if (str_starts_with($f, rtrim($dir->textContent, "/")."/")) { $found[] = $f; $ts->appendChild($doc->createElement("exclude", $f)); }
                }
            }
        }
        foreach ([...$files, ...$dirs] as $want) { if (! in_array($want, $found, true) || ! file_exists($want)) { fwrite(STDERR, "phpunit.xml no longer has $want\n"); exit(1); } }
        $suites->appendChild($linux);
        $doc->formatOutput = true; $doc->save("phpunit.gate.xml");' \
        "$linux_only_suite" "$(IFS=,; echo "${linux_only_files[*]-}")" "$(IFS=,; echo "${linux_only_dirs[*]}")") \
        >> "$logs/00-setup.log" 2>&1 || { echo "merge-gate: could not write phpunit.gate.xml (see $logs/00-setup.log)" >&2; result=FAIL; finish; }
    sqlite_args+=(--configuration=phpunit.gate.xml --exclude-testsuite="$linux_only_suite")
    pgsql_args=(--parallel --processes="$processes" --configuration=phpunit.gate.xml --exclude-testsuite="Unit,Arch,$linux_only_suite")
fi

# ---------------------------------------------------------------- CI's steps, in CI's order
when "$code" "postgres pin matches compose.yaml" bash -c '
    pattern="postgres:[0-9.]+-alpine[0-9.]*@sha256:[0-9a-f]{64}"
    a=$(grep -Eo "$pattern" compose.yaml | sort -u); b=$(grep -Eo "$pattern" ../.github/workflows/ci.yml | sort -u)
    c=$(grep -Eo "$pattern" ../deploy/compose.prod.yaml 2>/dev/null | sort -u)
    echo "compose.yaml: ${a:-<none>}"; echo "ci.yml: ${b:-<none>}"; echo "compose.prod.yaml: ${c:-<none>}"
    [ -n "$a" ] && [ "$(wc -l <<< "$a")" -eq 1 ] && [ "$a" = "$b" ] && [ "$a" = "$c" ]'
when "$policy" "lockfiles in sync" composer validate --no-check-publish
when "$audit" "security audits" bash -c '
    composer audit --locked && npm audit --audit-level=high --package-lock-only \
        && (cd ../docs-site && npm audit --audit-level=high --package-lock-only)'
when "$policy" "dependency cooldown and pinning" node scripts/check-dependency-age.mjs "$([ "$dep_base" = --all ] && echo --all || echo "--base=$dep_base")"
step "vp check (format + lint)" npx vp check
step "image sizes" npm run images:check
when "$code" "Pint" vendor/bin/pint --parallel --max-processes="$processes" --test
when "$code" "TypeScript" npx tsc --noEmit
when "$code" "Vitest" env LARAVEL_BYPASS_ENV_CHECK=1 npm test
when "$code" "PHPStan" vendor/bin/phpstan analyse --memory-limit=1G --no-progress --configuration=phpstan.gate.neon
when "$code" "Pest (SQLite)" bash -c 'php artisan config:clear -q && vendor/bin/pest "$@"' _ "${sqlite_args[@]}"
if [ "$os" = Darwin ]; then
    when "$code" "Pest Linux-only tests (linux.sh, SQLite)" "$scripts/linux.sh" \
        vendor/bin/pest --colors=never --configuration=phpunit.gate.xml --testsuite="$linux_only_suite"
fi

if [ "$code" = true ]; then
    pg_used=1 # set before pg.sh so a half-created database is still dropped
    pg_env="$("$scripts/pg.sh" "$pg_slug")" || { printf '%-44s ' "Pest (PostgreSQL)"; row "Pest (PostgreSQL)" FAIL - "pg.sh $pg_slug failed"; result=FAIL; finish; }
    eval "$pg_env"
    # composer test:pgsql's command, with this machine's test-suite exclusions.
    step "Pest (PostgreSQL)" bash -c 'php artisan config:clear -q && vendor/bin/pest "$@"' _ "${pgsql_args[@]}"
    unset DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD
fi

when "$code" "build" npm run build
# Last, like CI: the app must run without the dev-only simulators package, on a fresh --no-dev vendor/.
[ "$code" = true ] && rm -rf "${app:?}/vendor"
when "$code" "app runs without dev dependencies" bash -c '
    composer install --no-dev --no-interaction --no-progress -q || exit 1
    commands="$(php artisan list --raw)" || exit 1
    if grep -q "^simulate:" <<< "$commands"; then echo "simulate:* commands are available without the dev dependencies"; exit 1; fi
    php -r "require \"vendor/autoload.php\"; exit(class_exists(\"Mohar\\\\Simulators\\\\SimulatorRegistry\") ? 1 : 0);"'

finish
