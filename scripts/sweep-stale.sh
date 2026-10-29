#!/usr/bin/env bash
# Safety net for what finish-workstream.sh and the agents missed: scripts/sweep-stale.sh [--apply [--dbs]]
# Dry run by default; --apply removes worktrees (and their merged branches). Test databases are only listed unless
# --dbs is given too: run `--apply --dbs` only when no test suite is running. It lists
#   - worktrees whose branch tip is the head of a merged PR and whose tree is clean (removed with the local branch);
#   - detached worktrees under _review/ with no commits outside origin, clean;
#   - leftover merge-gate worktrees with no lock;
#   - test databases (pg.sh --list) whose slug has no worktree left.
# Anything dirty, with commits outside origin, or whose PR is not merged is listed as "kept: <reason>" and never touched.
# A tree that could be in use is kept too: one with a .claude-in-use file at its root (agents create it, git-ignored), and
# any branch or _review/ tree whose git HEAD or index, or top-level files (also in billing/), changed in the last
# SWEEP_IDLE_HOURS (default 12; 0 turns the check off). That is a handful of stat calls, no walk of vendor/ or node_modules/. The sweep reads
# trees with `git --no-optional-locks`, so it does not refresh an index and fake activity.
# Databases with open connections are skipped by pg.sh. A database is matched to a worktree by its directory name
# (pg.sh --db-name), so scratch databases of an agent that used another slug are swept too: run --apply when no suite runs.
# Works on git metadata only (git --git-dir); never changes the main checkout's files. Bash 3.2 compatible.
set -uo pipefail
export LC_ALL=C

apply=0; dbs_apply=0
for a in "$@"; do
    case "$a" in
        --apply) apply=1 ;;
        --dbs) dbs_apply=1 ;;
        *) echo "usage: scripts/sweep-stale.sh [--apply [--dbs]]" >&2; exit 1 ;;
    esac
done
[ "$dbs_apply" = 0 ] || [ "$apply" = 1 ] || { echo "sweep-stale: --dbs needs --apply" >&2; exit 1; }
idle_min=$(( ${SWEEP_IDLE_HOURS:-12} * 60 ))

here="$(cd "$(dirname "$0")" && pwd)"
common="$(git -C "$here" rev-parse --path-format=absolute --git-common-dir)" || exit 1
main="$(dirname "$common")"
g() { git --git-dir="$common" "$@"; }
gh_repo="${GATE_REPO:-$(g remote get-url origin | sed -E 's#^(git@[^:]+:|ssh://git@[^/]+/|https?://[^/]+/)##; s#\.git$##')}"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
cd / || exit 1
here_wt="$(git -C "$here" rev-parse --show-toplevel 2>/dev/null)"
verb="would remove"; [ "$apply" = 1 ] && verb="removed"
removed=0; kept=0

g fetch -q origin 2>/dev/null || echo "warning: git fetch failed; the origin checks may be stale" >&2
gh pr list -R "$gh_repo" --state merged --limit 5000 --json headRefName,headRefOid --jq '.[] | "\(.headRefName) \(.headRefOid)"' > "$tmp/merged" 2>"$tmp/gh.err" \
    || { echo "gh pr list failed ($(head -c 200 "$tmp/gh.err")); cannot tell merged PRs, so no branch worktree is removed" >&2; : > "$tmp/merged"; ghfail=1; }
ghfail="${ghfail:-0}"

keep() { echo "kept: $1: $2"; kept=$((kept + 1)); }

remove() { # remove <path> <reason> [<branch>]
    echo "$verb: $1 ($2)"
    if [ "$apply" = 1 ]; then
        if g worktree remove "$1"; then
            [ -z "${3:-}" ] || g branch -D "$3" > /dev/null
        else
            echo "kept: $1: git could not remove it"; return
        fi
    else
        echo "$1" >> "$tmp/gone"
    fi
    removed=$((removed + 1))
}

# recently_used <worktree>: true when its git HEAD or index, or a top-level entry of it or of billing/, changed lately.
recently_used() {
    local gd
    [ "$idle_min" -gt 0 ] || return 1
    gd="$(git -C "$1" rev-parse --absolute-git-dir 2>/dev/null)" || return 1
    [ -n "$(find "$gd/HEAD" "$gd/index" "$1" "$1/billing" -maxdepth 1 -mmin "-$idle_min" ! -name .git 2>/dev/null | head -1)" ]
}

handle() { # handle <path> <head> <branch or ""> <prunable: 0|1>
    local wt="$1" head="$2" branch="$3" prunable="$4" outside
    [ "$wt" != "$main" ] || return 0
    if [ "$prunable" = 1 ]; then echo "prunable: $wt (directory is gone; git worktree prune)"; return; fi
    if [ "$wt" = "$here_wt" ]; then keep "$wt" "the worktree this script runs from"; return; fi
    if [ -e "$wt/.claude-in-use" ]; then keep "$wt" "marked in use (.claude-in-use)"; return; fi
    case "$wt" in
        */.merge-gate/gate-*)
            if [ -d "${wt}.lock" ]; then keep "$wt" "a merge-gate run holds its lock"
            elif [ -n "$(git -C "$wt" --no-optional-locks status --porcelain 2>/dev/null)" ]; then keep "$wt" "uncommitted changes"
            else remove "$wt" "leftover merge-gate worktree, clean"; fi
            return ;;
    esac
    if recently_used "$wt"; then keep "$wt" "active in the last $((idle_min / 60)) h"; return; fi
    if [ -n "$(git -C "$wt" --no-optional-locks status --porcelain 2>/dev/null)" ]; then keep "$wt" "uncommitted changes"; return; fi
    if [ -z "$branch" ]; then
        case "$wt" in
            */_review/*)
                outside="$(g rev-list "$head" --not --remotes=origin | wc -l | tr -d ' ')"
                if [ "$outside" = 0 ]; then remove "$wt" "detached review tree, all commits on origin"; else keep "$wt" "detached, $outside commit(s) outside origin"; fi ;;
            *) keep "$wt" "detached and not under _review/" ;;
        esac
        return
    fi
    if grep -Fxq "$branch $head" "$tmp/merged"; then
        remove "$wt" "PR merged, clean; deletes branch $branch" "$branch"
    elif grep -q "^$(printf '%s' "$branch" | sed 's/[][\.*^$/]/\\&/g') " "$tmp/merged"; then
        keep "$wt" "branch $branch has commits newer than its merged PR head"
    elif [ "$ghfail" = 1 ]; then
        keep "$wt" "merged PRs unknown (gh failed)"
    else
        outside="$(g rev-list "$head" --not --remotes=origin | wc -l | tr -d ' ')"
        keep "$wt" "PR of $branch not merged ($outside commit(s) outside origin)"
    fi
}

path=""; head=""; branch=""; prunable=0
while IFS= read -r line; do
    case "$line" in
        "worktree "*) path="${line#worktree }"; head=""; branch=""; prunable=0 ;;
        "HEAD "*) head="${line#HEAD }" ;;
        "branch "*) branch="${line#branch refs/heads/}" ;;
        prunable*) prunable=1 ;;
        "") [ -z "$path" ] || handle "$path" "$head" "$branch" "$prunable"; path="" ;;
    esac
done < <(g worktree list --porcelain; echo)

if [ "$apply" = 1 ]; then g worktree prune; fi

# Test databases whose slug has no remaining worktree. Worktrees removed above no longer count.
dbs=0
if command -v docker > /dev/null 2>&1 && docker ps --format '{{.Names}}' | grep -qx mohar-pg; then
    : > "$tmp/live"
    g worktree list --porcelain | sed -n 's/^worktree //p' | while IFS= read -r wt; do
        if [ "$apply" = 1 ] || ! grep -Fxq "$wt" "$tmp/gone" 2> /dev/null; then
            [ -d "$wt" ] && "$here/pg.sh" --db-name "$(basename "$wt")" >> "$tmp/live"
        fi
    done
    "$here/pg.sh" --list | while IFS= read -r db; do
        [ -n "$db" ] || continue
        # Also keep the seeded copies of a live worktree's database (<db>_<name>, cut at 54 bytes plus a hash when long).
        live=0
        while IFS= read -r base; do
            case "$db" in "${base:0:54}"*) live=1; break ;; esac
        done < "$tmp/live"
        [ "$live" = 0 ] || continue
        if [ "$dbs_apply" = 1 ]; then "$here/pg.sh" --drop-db "$db"; else "$here/pg.sh" --drop-db --dry-run "$db"; fi
    done > "$tmp/dbs"
    cat "$tmp/dbs"
    dbs="$(grep -c '^\(would drop\|dropped\) ' "$tmp/dbs" || true)"
else
    echo "mohar-pg is not running (or docker is missing): test databases not checked"
fi

if [ "$dbs_apply" = 1 ]; then dbnote="dropped"; else dbnote="listed, not dropped (--apply --dbs, with no suite running)"; fi
echo "== $removed worktree(s) $verb, $kept kept, ${dbs:-0} database(s) $dbnote$([ "$apply" = 1 ] || echo '; rerun with --apply to act')"
