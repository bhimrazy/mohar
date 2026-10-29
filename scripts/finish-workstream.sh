#!/usr/bin/env bash
# Clean up after a merged workstream: scripts/finish-workstream.sh [--dry-run] <slug>
# Run right after `gh pr merge`. It first decides whether the work is safe, and changes nothing unless it is:
#   - the worktree (.claude/worktrees/<slug>, or the old .worktrees/<slug>) has no uncommitted changes, and
#   - its HEAD (detached included) is the head of a merged PR, or none of its commits are missing from origin.
# Otherwise it prints why and exits non-zero (1 dirty or bad input, 2 unmerged or unpushed commits), so a mistyped slug
# cannot cost a worktree. When safe it
#   1. removes the worktree,
#   2. deletes the local branch (the one the worktree had, or feature/<slug>),
#   3. drops the slug's test databases (scripts/pg.sh --drop: base, parallel and seeded copies), skipping any in use;
#      exits 3 if that step fails.
# Works on git metadata only (git --git-dir), so the main checkout's files are never touched. Safe to run twice.
# The whole script is parsed before it runs, because it may delete the checkout it was started from.
set -uo pipefail
export LC_ALL=C

main_fn() {
    local dry=0 slug here common main g gh_repo wt="" d branch="" head="" tip="" merged outside ghnote="" ghout scripts rc=0
    [ "${1:-}" = "--dry-run" ] && { dry=1; shift; }
    slug="${1:-}"
    [[ "$slug" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || { echo "usage: scripts/finish-workstream.sh [--dry-run] <slug>" >&2; return 1; }

    here="$(cd "$(dirname "$0")" && pwd)"
    common="$(git -C "$here" rev-parse --path-format=absolute --git-common-dir)" || return 1
    main="$(dirname "$common")"
    gh_repo="${GATE_REPO:-$(git --git-dir="$common" remote get-url origin | sed -E 's#^(git@[^:]+:|ssh://git@[^/]+/|https?://[^/]+/)##; s#\.git$##')}"
    g() { git --git-dir="$common" "$@"; }
    say() { if [ "$dry" = 1 ]; then echo "would: $*"; else echo "$*"; fi; }
    cd / || return 1

    g fetch -q origin 2>/dev/null || echo "warning: git fetch failed; the origin check may be stale" >&2

    for d in "$main/.claude/worktrees/$slug" "$main/.worktrees/$slug"; do
        [ -e "$d/.git" ] && { wt="$d"; break; }
    done
    if [ -n "$wt" ]; then
        branch="$(git -C "$wt" branch --show-current 2>/dev/null)"
        head="$(git -C "$wt" rev-parse HEAD)" || { echo "refused: cannot read HEAD of $wt" >&2; return 1; }
        if [ -n "$(git -C "$wt" --no-optional-locks status --porcelain 2>/dev/null)" ]; then
            echo "refused: $wt has uncommitted changes; commit or discard them first. Nothing was changed." >&2
            return 1
        fi
    else
        echo "no worktree for '$slug' under .claude/worktrees/ or .worktrees/"
        branch="feature/$slug"
        g show-ref --verify --quiet "refs/heads/$branch" && head="$(g rev-parse "refs/heads/$branch")"
    fi

    if [ -z "$head" ]; then
        echo "no local branch $branch; nothing to remove"
    else
        merged=""
        if [ -n "$branch" ]; then
            if ghout="$(gh pr list -R "$gh_repo" --state merged --head "$branch" --json headRefOid --jq '.[].headRefOid' 2>&1)"; then
                merged="$(printf '%s\n' "$ghout" | grep -Fx "$head" || true)"
            else
                ghnote=" (gh failed, so merged PRs are unknown: $(printf '%s' "$ghout" | head -c 200))"
            fi
        fi
        outside="$(g rev-list "$head" --not --remotes=origin | wc -l | tr -d ' ')"
        if [ -n "$merged" ]; then
            echo "safe: ${head:0:9} is the head of a merged PR${branch:+ ($branch)}"
        elif [ "$outside" = 0 ]; then
            echo "safe: all commits of ${head:0:9} are on origin"
        else
            echo "refused: ${branch:-detached HEAD} at ${head:0:9} is not the head of a merged PR and $outside commit(s) are not on origin${ghnote}. Push or merge it, then rerun. Nothing was changed." >&2
            return 2
        fi
    fi

    if [ -n "$wt" ]; then
        say "remove worktree $wt"
        [ "$dry" = 1 ] || g worktree remove "$wt" || { echo "refused: git could not remove $wt" >&2; return 1; }
    fi
    if [ -n "$branch" ] && g show-ref --verify --quiet "refs/heads/$branch"; then
        say "delete branch $branch"
        [ "$dry" = 1 ] || g branch -D "$branch" > /dev/null
    fi

    # The target worktree may hold this script; use the main checkout's copy of the helper then.
    scripts="$here"
    if [ -n "$wt" ]; then case "$here/" in "$wt"/*) scripts="$main/scripts" ;; esac; fi
    if command -v docker > /dev/null 2>&1; then
        if [ "$dry" = 1 ]; then "$scripts/pg.sh" --drop --dry-run "$slug"; else "$scripts/pg.sh" --drop "$slug"; fi || { echo "database step failed" >&2; rc=3; }
    else
        echo "docker not found; test databases not checked"
    fi
    return $rc
}

main_fn "$@"
exit $?
