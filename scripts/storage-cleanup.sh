#!/usr/bin/env bash
# Reclaim disk space on the codespace without losing work.
#   scripts/storage-cleanup.sh            report only (dry run)
#   scripts/storage-cleanup.sh --apply    perform the safe actions below
#   LOW_SPACE_GB=4 scripts/storage-cleanup.sh --apply   also clear the npm cache when free space is below 4 GB
#
# Safe actions (never touch uncommitted, unpushed or in-progress work):
#   1. Remove a worktree only when its branch's PR is MERGED, the worktree is clean and nothing is unpushed.
#   2. Clear Laravel compiled views (untouched for 1h) and test scratch (untouched for 2h) in every
#      worktree, so running suites are never disturbed; truncate logs over 50 MB.
#   3. Remove stopped containers older than 2 hours and dangling Docker images.
#   4. git gc --auto on the main repository.
#   5. Clear the npm cache only when free space is below LOW_SPACE_GB (default 4).
set -uo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
apply=false
[ "${1:-}" = "--apply" ] && apply=true
low_gb="${LOW_SPACE_GB:-4}"

run() { if $apply; then "$@"; else echo "  would run: $*"; fi; }
free_gb() { df -BG --output=avail / | tail -1 | tr -dc '0-9'; }

echo "== $(date -u +%FT%TZ) storage cleanup (apply=$apply), free before: $(free_gb) GB"

git -C "$root" fetch -q origin 2>/dev/null
git -C "$root" worktree list --porcelain | awk '/^worktree /{print $2}' | tail -n +2 | while read -r wt; do
    branch="$(git -C "$wt" branch --show-current 2>/dev/null)"
    [ -n "$branch" ] || continue
    dirty="$(git -C "$wt" status --porcelain 2>/dev/null | wc -l)"
    unpushed="$(git -C "$wt" rev-list --count "origin/$branch..HEAD" 2>/dev/null || echo unknown)"
    merged_pr="$(gh pr list --state merged --head "$branch" --json number -q '.[0].number' 2>/dev/null)"
    if [ -n "$merged_pr" ] && [ "$dirty" = 0 ] && [ "$unpushed" = 0 ]; then
        echo "- worktree $wt: PR #$merged_pr merged, clean, pushed -> remove"
        run git -C "$root" worktree remove "$wt"
        run git -C "$root" branch -D "$branch"
    else
        echo "- worktree $wt: keep (branch $branch, PR merged: ${merged_pr:-no}, dirty files: $dirty, unpushed: $unpushed)"
    fi

    app="$wt/billing"
    [ -d "$app/storage" ] || continue
    run find "$app/storage/framework/views" -type f -name '*.php' -mmin +60 -delete
    run find "$app/storage/framework/testing" -mindepth 1 -mmin +120 -delete
    find "$app/storage/logs" -type f -name '*.log' -size +50M 2>/dev/null | while read -r log; do
        echo "  log over 50 MB: $log"
        run truncate -s 0 "$log"
    done
done

if command -v docker >/dev/null 2>&1; then
    echo "- docker: stopped containers older than 2h and dangling images"
    run docker container prune -f --filter "until=2h"
    run docker image prune -f
fi

echo "- git gc --auto"
run git -C "$root" gc --auto --quiet

if [ "$(free_gb)" -lt "$low_gb" ]; then
    echo "- free space below ${low_gb} GB: clear npm cache"
    run npm cache clean --force
fi

echo "== free after: $(free_gb) GB"
