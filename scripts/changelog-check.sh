#!/usr/bin/env bash
# scripts/changelog-check.sh [--marker-rev <rev>] [<base> [<head>]]   CI's changelog policy (ADR 0027).
#
# Run inside the repository with <head> checked out; the defaults (HEAD^1, HEAD) are a PR's merge commit in CI.
#   - Fails when the diff edits billing/changelog.md or billing/docs/architecture/kernel-changelog.md, unless
#     <marker-rev> (default <head>; CI passes HEAD^2, the PR head) carries the trailer "Changelog-Assemble: yes" and
#     the diff only edits those two files and deletes fragments: that is the assemble commit.
#   - Fails when a fragment is malformed (scripts/changelog-assemble.sh --check).
#   - Warns, without failing, when app code changes and no billing/changelog.d/ fragment is added or edited, and when
#     the kernel surface (ADR 0010) changes and no kernel-changelog.d/ fragment is.
# Env: BASE_REF, the PR's base branch (a release PR into main carries develop's assembled changelog and is not
# checked); PR_NUMBER (PRs up to LEGACY_MAX_PR, opened before fragments, keep their direct edits with a warning).
set -euo pipefail
export LC_ALL=C

# The highest PR number open when fragments were introduced. Remove the exception once those PRs have merged.
LEGACY_MAX_PR=393

marker_rev=""
while [ $# -gt 0 ]; do
    case "$1" in
        --marker-rev) marker_rev="${2:?--marker-rev needs a revision}"; shift 2 ;;
        *) break ;;
    esac
done
base="${1:-HEAD^1}"
head="${2:-HEAD}"
marker_rev="${marker_rev:-$head}"
here="$(cd "$(dirname "$0")" && pwd)"
top="$(git rev-parse --show-toplevel)"

annotate() { # annotate <error|warning> <file> <message>
    if [ "${GITHUB_ACTIONS:-}" = true ]; then echo "::$1 file=$2::$3"; else echo "$1: $2: $3"; fi
}

if [ "${BASE_REF:-}" = main ]; then
    echo "changelog-check: release PR into main; not checked"
    exit 0
fi

app_log='billing/changelog.md'
kernel_log='billing/docs/architecture/kernel-changelog.md'
app_frag_re='^billing/changelog[.]d/[^/]+[.]md$'
kernel_frag_re='^billing/docs/architecture/kernel-changelog[.]d/[^/]+[.]md$'
code_re='^billing/(app|bootstrap|config|database|lang|resources|routes)/'
kernel_re='^billing/app/((Contracts|Data|Events|Enums)/|Support/(.+Registry|Money)[.]php$)'

# "<status>\t<path>" per changed file; renames show as a delete plus an add.
changes="$(git diff --name-status --no-renames "$base" "$head")"
paths="$(cut -f2- <<< "$changes")"
added_or_edited() { awk -F '\t' -v re="$1" '$1 != "D" && $2 ~ re && $2 !~ /\/README[.]md$/' <<< "$changes"; }

failed=0
direct="$(grep -xE "$app_log|$kernel_log" <<< "$paths" || true)"
if [ -n "$direct" ]; then
    if git log -1 --format=%B "$marker_rev" | grep -qx 'Changelog-Assemble: yes'; then
        others="$(awk -F '\t' -v a="$app_log" -v k="$kernel_log" -v af="$app_frag_re" -v kf="$kernel_frag_re" \
            '$2 != a && $2 != k && !($1 == "D" && ($2 ~ af || $2 ~ kf))' <<< "$changes")"
        if [ -n "$others" ]; then
            annotate error "$app_log" "an assemble commit only edits the two changelogs and deletes fragments; it also changes: $(cut -f2 <<< "$others" | tr '\n' ' ')"
            failed=1
        else
            echo "changelog-check: assemble commit ($(git rev-parse --short "$marker_rev")); direct edits allowed"
        fi
    elif [ -n "${PR_NUMBER:-}" ] && [ "$PR_NUMBER" -le "$LEGACY_MAX_PR" ]; then
        for f in $direct; do
            annotate warning "$f" "PR #$PR_NUMBER predates changelog fragments and keeps its direct edit; new entries go in a fragment (billing/changelog.d/README.md)"
        done
    else
        for f in $direct; do
            case "$f" in
                "$app_log") dir='billing/changelog.d' ;;
                *) dir='billing/docs/architecture/kernel-changelog.d' ;;
            esac
            annotate error "$f" "do not edit $f directly; add $dir/<branch-slug>.md instead (format: $dir/README.md, ADR 0027). Only the assemble commit (scripts/changelog-assemble.sh) edits it."
        done
        failed=1
    fi
fi

if ! "$here/changelog-assemble.sh" --check --root "$top"; then
    annotate error billing/changelog.d "a changelog fragment is malformed; see the messages above"
    failed=1
fi

if grep -qE "$code_re" <<< "$paths" && [ -z "$(added_or_edited "$app_frag_re")" ]; then
    annotate warning billing/changelog.d "app code changed but no billing/changelog.d/ fragment was added; add one unless users and operators see no change"
fi
if grep -qE "$kernel_re" <<< "$paths" && [ -z "$(added_or_edited "$kernel_frag_re")" ]; then
    annotate warning billing/docs/architecture/kernel-changelog.d "the kernel surface changed (ADR 0010) but no kernel-changelog.d/ fragment was added; add one unless the public API is unchanged"
fi

exit "$failed"
