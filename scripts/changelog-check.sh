#!/usr/bin/env bash
# scripts/changelog-check.sh [--marker-rev <rev>] [<base> [<head>]]   CI's changelog policy (ADR 0027).
#
# Run inside the repository with <head> checked out; the defaults (HEAD^1, HEAD) are a PR's merge commit in CI.
#   - Fails when the diff edits billing/changelog.md or billing/docs/architecture/kernel-changelog.md, unless
#     <marker-rev> (default <head>; CI passes HEAD^2, the PR head) carries the trailer "Changelog-Assemble: yes" and
#     the diff only edits those two files and deletes fragments: that is the assemble commit.
#   - Fails when a fragment is malformed (scripts/changelog-assemble.sh --check).
#   - Fails when the kernel API snapshot (billing/docs/architecture/kernel-api.txt, ADR 0010) changes and no
#     kernel-changelog.d/ fragment is added or edited: every recorded surface change has an entry. Fails too when the
#     snapshot changes and no billing/app/ file does: a hand edit, which the docs-only path (no Pest) would let through.
#   - Warns, without failing, when app code changes and no billing/changelog.d/ fragment is added or edited, and when
#     the kernel surface (ADR 0010) changes and no kernel-changelog.d/ fragment is.
# Env: BASE_REF, the PR's base branch (a release PR into main carries develop's assembled changelog and is not
# checked); HEAD_REF, the PR's head branch (a back-merge from main brings that changelog back and is not checked either);
# HEAD_REPO and REPOSITORY, the head's and the base's owner/repo (when HEAD_REPO is set, only a head branch main of this
# repository counts as a back-merge, so a fork's branch named main is checked); PR_NUMBER (PRs up to LEGACY_MAX_PR,
# opened before fragments, keep their direct edits with a warning).
set -euo pipefail
export LC_ALL=C

# The highest PR number open when fragments were introduced. Remove the exception once those PRs have merged.
LEGACY_MAX_PR=396

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

back_merge=false
if [ "${HEAD_REF:-}" = main ] && { [ -z "${HEAD_REPO:-}" ] || [ "$HEAD_REPO" = "${REPOSITORY:-}" ]; }; then
    back_merge=true
fi
if [ "${BASE_REF:-}" = main ] || [ "$back_merge" = true ]; then
    echo "changelog-check: release PR or back-merge from main; not checked"
    exit 0
fi

app_log='billing/changelog.md'
kernel_log='billing/docs/architecture/kernel-changelog.md'
app_frag_re='^billing/changelog[.]d/[^/]+[.]md$'
kernel_frag_re='^billing/docs/architecture/kernel-changelog[.]d/[^/]+[.]md$'
code_re='^billing/(app|bootstrap|config|database|lang|resources|routes)/'
# Support classes are covered by the snapshot error; the warning still catches docblock-only edits in these folders.
kernel_re='^billing/app/(Contracts|Data|Events|Enums)/'
kernel_api='billing/docs/architecture/kernel-api.txt'

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
            annotate warning "$f" "PR #$PR_NUMBER predates changelog fragments and keeps its direct edit; later entries go in a fragment (ADR 0027)"
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

# A legacy PR's direct edit stands in for the fragment.
if grep -qE "$code_re" <<< "$paths" && ! grep -qx "$app_log" <<< "$direct" && [ -z "$(added_or_edited "$app_frag_re")" ]; then
    annotate warning billing/changelog.d "app code changed but no billing/changelog.d/ fragment was added; add one unless users and operators see no change"
fi
if grep -qx "$kernel_api" <<< "$paths" && ! grep -q '^billing/app/' <<< "$paths"; then
    annotate error "$kernel_api" "the kernel API snapshot changed but no billing/app/ file did; regenerate it with php artisan kernel:api-snapshot instead of editing it (ADR 0010)"
    failed=1
fi
if grep -qx "$kernel_api" <<< "$paths" && ! grep -qx "$kernel_log" <<< "$direct" && [ -z "$(added_or_edited "$kernel_frag_re")" ]; then
    annotate error "$kernel_api" "the kernel API snapshot changed but no kernel-changelog.d/ fragment was added; add billing/docs/architecture/kernel-changelog.d/<branch-slug>.md describing the change (format: that folder's README.md, ADR 0010)"
    failed=1
elif grep -qE "$kernel_re" <<< "$paths" && ! grep -qx "$kernel_log" <<< "$direct" && [ -z "$(added_or_edited "$kernel_frag_re")" ]; then
    annotate warning billing/docs/architecture/kernel-changelog.d "the kernel surface changed (ADR 0010) but no kernel-changelog.d/ fragment was added; add one unless the public API is unchanged"
fi

exit "$failed"
