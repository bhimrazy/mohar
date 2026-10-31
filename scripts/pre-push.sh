#!/usr/bin/env bash
# Fast local gate before a push, the checks CI's first jobs would fail on (best-practices audit M7):
#   scripts/pre-push.sh [--dry-run]     --dry-run prints the plan (which check runs on which files) and runs nothing.
# Installed per worktree by scripts/new-worktree.sh (scripts/install-hooks.sh); git runs it through scripts/githooks/pre-push.
# Bypass in an emergency with `git push --no-verify`; PRE_PUSH_SKIP=tests,phpstan skips single checks
# (pint, vp, changelog, phpstan, tests). PRE_PUSH_BASE overrides origin/develop.
#
# Checks, on what the branch changes against the base (committed changes only):
#   pint --test on changed PHP, vp check on changed frontend/docs files under billing/, scripts/changelog-check.sh,
#   phpstan on changed analysed PHP, scripts/test.sh --changed. A docs-only push runs just vp check and the changelog
#   check. When test.sh would run the whole suite (shared code changed) the tests are left to CI, unless PRE_PUSH_FULL=1.
# CPU-heavy commands go through scripts/heavy.sh. Every failing check is reported, then the push is refused.
set -uo pipefail
export LC_ALL=C XDEBUG_MODE=off

scripts="$(cd "$(dirname "$0")" && pwd)"
top="$(git rev-parse --show-toplevel)"
dry=0
[ "${1:-}" = "--dry-run" ] && dry=1

# Deleting a branch or pushing only tags: nothing to check. Manual runs (a terminal) have no ref lines.
if [ "$dry" = 0 ] && [ ! -t 0 ]; then
    refs=0 branches=0
    while read -r local_ref local_sha _; do
        refs=$((refs + 1))
        case "$local_ref" in refs/heads/*) [ "${local_sha//0/}" != "" ] && branches=$((branches + 1)) ;; esac
    done
    if [ "$refs" -gt 0 ] && [ "$branches" -eq 0 ]; then echo "pre-push: no branch update, nothing to check"; exit 0; fi
fi

base_ref="${PRE_PUSH_BASE:-origin/develop}"
if ! base="$(git -C "$top" merge-base "$base_ref" HEAD 2>/dev/null)"; then
    echo "pre-push: $base_ref not found (git fetch origin develop, or set PRE_PUSH_BASE); skipping the gate, CI still runs" >&2
    exit 0
fi

files="$(git -C "$top" diff --name-only --diff-filter=ACMR "$base" HEAD)"
pint_files=() vp_files=() stan_files=() code=0
while IFS= read -r f; do
    [ -n "$f" ] || continue
    case "$f" in
        billing/vendor/* | billing/node_modules/*) continue ;;
        billing/*.php)
            pint_files+=("${f#billing/}")
            case "$f" in
                billing/app/* | billing/bootstrap/app.php | billing/config/* | billing/database/* | billing/routes/* | billing/tools/simulators/src/*)
                    stan_files+=("${f#billing/}") ;;
            esac
            code=1 ;;
        billing/*.ts | billing/*.tsx | billing/*.js | billing/*.jsx | billing/*.mjs | billing/*.css | billing/*.json | billing/*.md)
            vp_files+=("${f#billing/}")
            case "$f" in billing/resources/js/* | billing/*.config.*) code=1 ;; esac ;;
    esac
done <<< "$files"

skipped() { case ",${PRE_PUSH_SKIP:-}," in *",$1,"*) return 0 ;; esac; return 1; }
failed=()
step() { # step <name> <description> <command...>
    local name="$1" what="$2"; shift 2
    if skipped "$name"; then echo "pre-push: $name skipped (PRE_PUSH_SKIP)"; return; fi
    if [ "$dry" = 1 ]; then echo "$name: $what"; return; fi
    echo "pre-push: $name ($what)"
    local start=$SECONDS
    "$@" || failed+=("$name")
    echo "pre-push: $name took $((SECONDS - start))s"
}
skip_step() { [ "$dry" = 1 ] && echo "$1: skip ($2)"; return 0; }

cd "$top/billing" || exit 1
total=$SECONDS

if [ ${#pint_files[@]} -gt 0 ]; then
    step pint "${pint_files[*]}" "$scripts/heavy.sh" vendor/bin/pint --test "${pint_files[@]}"
else skip_step pint "no changed PHP"; fi

if [ ${#vp_files[@]} -gt 0 ]; then
    step vp "${vp_files[*]}" "$scripts/heavy.sh" npx --no-install vp check --no-error-on-unmatched-pattern "${vp_files[@]}"
else skip_step vp "no changed frontend or docs files under billing/"; fi

step changelog "$base..HEAD" "$scripts/changelog-check.sh" "$base" HEAD

if [ ${#stan_files[@]} -gt 0 ]; then
    step phpstan "${stan_files[*]}" "$scripts/heavy.sh" vendor/bin/phpstan analyse --memory-limit=1G --no-progress "${stan_files[@]}"
else skip_step phpstan "no changed analysed PHP"; fi

if [ "$code" = 0 ]; then
    skip_step tests "docs-only or non-code change"
else
    selection="$(TEST_BASE="$base" "$scripts/test.sh" --changed --list 2>&1)"
    if grep -qx "whole suite" <<< "$selection" && [ -z "${PRE_PUSH_FULL:-}" ]; then
        skip_step tests "shared code changed, the whole suite is left to CI (PRE_PUSH_FULL=1 runs it)"
    else
        step tests "scripts/test.sh --changed" env TEST_BASE="$base" "$scripts/test.sh" --changed
    fi
fi

[ "$dry" = 1 ] && exit 0
echo "pre-push: done in $((SECONDS - total))s"
if [ ${#failed[@]} -gt 0 ]; then
    echo "pre-push: FAILED: ${failed[*]}. Fix them, or push anyway with 'git push --no-verify' (emergencies only)." >&2
    exit 1
fi
