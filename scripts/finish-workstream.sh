#!/usr/bin/env bash
# Clean up after a merged workstream: scripts/finish-workstream.sh [--dry-run] <slug>
# Run right after `gh pr merge`. It
#   1. removes the worktree .claude/worktrees/<slug> (or the old .worktrees/<slug>); refuses if it has uncommitted changes;
#   2. deletes the local branch (feature/<slug>, or the one the worktree had), only if its tip is the head of a merged PR
#      or has no commits outside origin; otherwise keeps it and says why (exit 2);
#   3. drops the slug's test databases (scripts/pg.sh --drop), skipping any in use.
# Works on git metadata only (git --git-dir), so the main checkout's files are never touched. Safe to run twice.
set -uo pipefail
export LC_ALL=C

dry=0
[ "${1:-}" = "--dry-run" ] && { dry=1; shift; }
slug="${1:-}"
[[ "$slug" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || { echo "usage: scripts/finish-workstream.sh [--dry-run] <slug>" >&2; exit 1; }

here="$(cd "$(dirname "$0")" && pwd)"
common="$(git -C "$here" rev-parse --path-format=absolute --git-common-dir)" || exit 1
main="$(dirname "$common")"
g() { git --git-dir="$common" "$@"; }
gh_repo="${GATE_REPO:-$(g remote get-url origin | sed -E 's#^(git@[^:]+:|ssh://git@[^/]+/|https?://[^/]+/)##; s#\.git$##')}"
say() { if [ "$dry" = 1 ]; then echo "would: $*"; else echo "$*"; fi; }
cd / || exit 1

g fetch -q origin 2>/dev/null || echo "warning: git fetch failed; the origin check may be stale" >&2

wt=""
for d in "$main/.claude/worktrees/$slug" "$main/.worktrees/$slug"; do
    [ -e "$d/.git" ] && { wt="$d"; break; }
done
branch="feature/$slug"
status=0

if [ -n "$wt" ]; then
    b="$(git -C "$wt" branch --show-current 2>/dev/null)"
    [ -n "$b" ] && branch="$b"
    if [ -n "$(git -C "$wt" status --porcelain 2>/dev/null)" ]; then
        echo "refused: $wt has uncommitted changes; commit or discard them first. Nothing was changed." >&2
        exit 1
    fi
    say "remove worktree $wt"
    [ "$dry" = 1 ] || g worktree remove "$wt" || { echo "refused: git could not remove $wt" >&2; exit 1; }
else
    echo "no worktree for '$slug' under .claude/worktrees/ or .worktrees/"
fi

if g show-ref --verify --quiet "refs/heads/$branch"; then
    tip="$(g rev-parse "refs/heads/$branch")"
    merged="$(gh pr list -R "$gh_repo" --state merged --head "$branch" --json headRefOid --jq '.[].headRefOid' 2>/dev/null | grep -Fx "$tip" || true)"
    outside="$(g rev-list "refs/heads/$branch" --not --remotes=origin | wc -l | tr -d ' ')"
    if [ -n "$merged" ]; then
        say "delete branch $branch (tip ${tip:0:9} is the head of a merged PR)"
        [ "$dry" = 1 ] || g branch -D "$branch" >/dev/null
    elif [ "$outside" = 0 ]; then
        say "delete branch $branch (no commits outside origin)"
        [ "$dry" = 1 ] || g branch -D "$branch" >/dev/null
    else
        echo "kept branch $branch: tip ${tip:0:9} is not a merged PR head and $outside commit(s) are not on origin. Push or merge it, then rerun." >&2
        status=2
    fi
else
    echo "no local branch $branch"
fi

if command -v docker >/dev/null 2>&1; then
    if [ "$dry" = 1 ]; then "$here/pg.sh" --drop --dry-run "$slug"; else "$here/pg.sh" --drop "$slug"; fi
else
    echo "docker not found; test databases not checked"
fi
exit $status
