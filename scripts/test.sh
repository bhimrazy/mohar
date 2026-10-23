#!/usr/bin/env bash
# Run the billing tests of the current worktree the way agents should, through scripts/heavy.sh:
#   cd <worktree>/billing && $MOHAR_ROOT/scripts/test.sh                 everything, on SQLite
#   $MOHAR_ROOT/scripts/test.sh --changed       only the tests related to what changed against origin/develop
#   $MOHAR_ROOT/scripts/test.sh --pgsql         on PostgreSQL (scripts/pg.sh <worktree slug>), as CI's PostgreSQL jobs
#   $MOHAR_ROOT/scripts/test.sh [options] <paths...> [-- <pest options>]   these paths only
#   $MOHAR_ROOT/scripts/test.sh --list --changed   print the selection and exit
# Options combine (--changed --pgsql). Run it from anywhere inside the worktree; it works in the worktree's billing/.
#
# --changed selects (from the diff of origin/develop...HEAD plus uncommitted and untracked files):
#   - changed test files, and the whole directory of a changed test helper;
#   - app/Modules/<M>/** -> tests/Feature/<M> and tests/Unit/<M>; tools/simulators/** -> tools/simulators/tests;
#     deploy/, loadtest/ -> tests/Unit/Deploy; scripts/, billing/scripts/, .github/ -> tests/Unit;
#   - always, once any PHP-side file changed: tests/Arch, tests/Extensions, tests/Feature/Security,
#     tests/Feature/Migration and tests/Feature/TestHarness, which catch breakage across modules and PRs;
#   - the whole suite when a shared file changed (the kernel and other app/ code outside app/Modules, config/, routes/,
#     bootstrap/, database/, resources/views, lang/, tests/Pest.php, tests/TestCase.php, tests/Support, composer.*,
#     phpunit.xml). Frontend-only (resources/js) and docs-only changes select nothing: run `npm test` for the former.
#   TEST_BASE overrides origin/develop; fetch first (git fetch origin develop) for an up-to-date base.
#
# Workers: --parallel with TEST_PROCESSES workers, by default picked from the load once heavy.sh gives a slot:
# TEST_LOAD_CEILING (8, the shared Mac's limit) minus the 1-minute load, at most 4; at least 2 for the whole suite,
# because one worker running everything passes the 1 GB memory_limit in phpunit.xml.
# macOS without setsid(1): the simulator tests leave the native run and run once through scripts/linux.sh (SQLite, in
# the production base image; Docker must be running).
set -euo pipefail
scripts="$(cd "$(dirname "$0")" && pwd)"

if [ -z "${TEST_SH_IN_SLOT:-}" ]; then
    TEST_SH_IN_SLOT=1 exec "$scripts/heavy.sh" "$scripts/$(basename "$0")" "$@"
fi

usage() { sed -n '2,7p' "$0" | sed 's/^# \{0,1\}//' >&2; exit 2; }

changed=0 pgsql=0 list=0
paths=() extra=()
while [ $# -gt 0 ]; do
    case "$1" in
        --changed) changed=1 ;;
        --pgsql) pgsql=1 ;;
        --list) list=1 ;;
        -h | --help) usage ;;
        --) shift; extra=("$@"); break ;;
        -*) echo "test.sh: unknown option $1 (pest options go after --)" >&2; usage ;;
        *) paths+=("$1") ;;
    esac
    shift
done

top="$(git rev-parse --show-toplevel)"
app="$top/billing"
cd "$app"

# ---------------------------------------------------------------- selection
full=1
if [ "$changed" = 1 ]; then
    base="${TEST_BASE:-origin/develop}"
    files="$(cd "$top" && { git diff --name-only "$base"...HEAD; git diff --name-only HEAD; git ls-files --others --exclude-standard; } | sort -u)"
    selection=()
    reason=""
    php_side=0
    add_dir() { if [ -d "$1" ]; then selection+=("$1"); fi; }
    while IFS= read -r f; do
        [ -n "$f" ] || continue
        case "$f" in
            *.md | *.png | *.jpg | *.svg | billing/docs/* | docs/* | docs-site/* | billing/changelog.d/* | billing/resources/js/* | billing/resources/css/*) ;;
            billing/tests/Pest.php | billing/tests/TestCase.php | billing/tests/Support/* | billing/tests/Fakes/* | billing/tests/Fixtures/*)
                reason="${reason:-$f}" ;;
            billing/tests/*Test.php | billing/tools/simulators/tests/*Test.php)
                php_side=1; [ -f "$top/$f" ] && selection+=("${f#billing/}") ;;
            billing/tests/* | billing/tools/simulators/tests/*)
                php_side=1; d="$(dirname "${f#billing/}")"; add_dir "$d" ;;
            billing/app/Modules/*)
                php_side=1; m="${f#billing/app/Modules/}"; m="${m%%/*}"; add_dir "tests/Feature/$m"; add_dir "tests/Unit/$m" ;;
            billing/tools/simulators/*)
                php_side=1; add_dir tools/simulators/tests ;;
            deploy/* | loadtest/*)
                php_side=1; add_dir tests/Unit/Deploy ;;
            scripts/* | billing/scripts/* | .github/*)
                php_side=1; add_dir tests/Unit ;;
            billing/*)
                reason="${reason:-$f}" ;;
            *) ;;
        esac
    done <<< "$files"

    if [ -n "$reason" ]; then
        echo "test.sh --changed: $reason is shared by every module, so the whole suite runs"
    elif [ "$php_side" = 0 ] && [ ${#paths[@]} -eq 0 ]; then
        echo "test.sh --changed: no PHP tests relate to the changes against $base (frontend: npm test)"
        exit 0
    else
        full=0
        for d in tests/Arch tests/Extensions tests/Feature/Security tests/Feature/Migration tests/Feature/TestHarness; do add_dir "$d"; done
        paths+=("${selection[@]}")
    fi
fi
if [ ${#paths[@]} -gt 0 ]; then
    full=0
fi

# Drop paths inside a directory that is selected too (pest would run them twice), and Unit/Arch on PostgreSQL: they
# never touch the database (CI runs them on SQLite only).
if [ "$full" = 0 ]; then
    sorted="$(printf '%s\n' "${paths[@]}" | sed 's#/$##' | sort -u)"
    paths=()
    while IFS= read -r p; do
        [ -n "$p" ] || continue
        if [ "$pgsql" = 1 ]; then case "$p" in tests/Unit | tests/Unit/* | tests/Arch | tests/Arch/*) continue ;; esac; fi
        covered=0
        for q in ${paths[@]+"${paths[@]}"}; do case "$p" in "$q"/*) covered=1 ;; esac; done
        [ "$covered" = 1 ] || paths+=("$p")
    done <<< "$sorted"
    if [ ${#paths[@]} -eq 0 ]; then
        echo "test.sh: nothing to run on PostgreSQL in the selection (only Unit/Arch tests)"
        exit 0
    fi
fi

# ---------------------------------------------------------------- Linux-only tests on macOS
# The simulators start their processes with setsid(1), which macOS lacks: there they run once through linux.sh.
linux=0
if [ "$(uname -s)" = Darwin ] && ! command -v setsid >/dev/null 2>&1; then linux=1; fi
linux_selected=()
native=()
if [ "$full" = 0 ]; then
    for p in "${paths[@]}"; do
        case "$p" in
            tools/simulators/tests | tools/simulators/tests/*) if [ "$linux" = 1 ]; then linux_selected+=("$p"); continue; fi ;;
        esac
        native+=("$p")
    done
elif [ "$linux" = 1 ]; then
    linux_selected=(tools/simulators/tests)
fi

if [ "$list" = 1 ]; then
    if [ "$full" = 1 ]; then echo "whole suite"; else printf '%s\n' ${native[@]+"${native[@]}"}; fi
    for p in ${linux_selected[@]+"${linux_selected[@]}"}; do echo "$p (through scripts/linux.sh)"; done
    exit 0
fi

# ---------------------------------------------------------------- workers
if [ -r /proc/loadavg ]; then load="$(cut -d' ' -f1 /proc/loadavg)"; else load="$(sysctl -n vm.loadavg | tr -d '{}' | awk '{print $1}')"; fi
ceiling="${TEST_LOAD_CEILING:-8}"
minimum=1
[ "$full" = 1 ] && minimum=2
processes="${TEST_PROCESSES:-$(awk -v l="$load" -v c="$ceiling" -v m="$minimum" 'BEGIN { p = int(c - l); if (p > 4) p = 4; if (p < m) p = m; print p }')}"
echo "test.sh: load $load (ceiling $ceiling) -> $processes workers; $([ "$pgsql" = 1 ] && echo PostgreSQL || echo SQLite)"

export XDEBUG_MODE=off
php artisan config:clear -q

args=(--parallel --processes="$processes")
excluded=()
[ "$pgsql" = 1 ] && excluded+=(Unit Arch)
[ "$full" = 1 ] && [ "$linux" = 1 ] && excluded+=("Simulator framework")
if [ ${#excluded[@]} -gt 0 ]; then
    args+=("--exclude-testsuite=$(IFS=,; echo "${excluded[*]}")")
fi

if [ "$pgsql" = 1 ]; then
    slug="$(basename "$top")"
    eval "$("$scripts/pg.sh" "$slug")"
fi

status=0
if [ "$full" = 1 ] || [ ${#native[@]} -gt 0 ]; then
    php vendor/bin/pest "${args[@]}" ${native[@]+"${native[@]}"} ${extra[@]+"${extra[@]}"} || status=$?
fi

if [ ${#linux_selected[@]} -gt 0 ]; then
    if [ "$pgsql" = 1 ]; then
        echo "test.sh: the Linux-only tests run on SQLite only: ${linux_selected[*]}"
    fi
    if ! docker info >/dev/null 2>&1; then
        echo "test.sh: Docker is not running, so these Linux-only tests did not run: ${linux_selected[*]}" >&2
        status=1
    else
        linux_processes=$((processes < 2 ? processes : 2))
        echo "test.sh: through scripts/linux.sh ($linux_processes workers): ${linux_selected[*]}"
        "$scripts/linux.sh" vendor/bin/pest --parallel --processes="$linux_processes" "${linux_selected[@]}" ${extra[@]+"${extra[@]}"} || status=$?
    fi
fi

exit "$status"
