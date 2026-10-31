#!/usr/bin/env bash
# Create an isolated worktree for a workstream: scripts/new-worktree.sh <module-slug>
# -> $MOHAR_ROOT/.claude/worktrees/<slug> on branch feature/<slug> (from a freshly fetched origin/develop, not the
# local develop, which goes stale), dependencies ready, the pre-push gate installed (scripts/install-hooks.sh).
set -euo pipefail
slug="${1:-}"
# finish-workstream.sh's rule: the slug becomes a directory, a branch and a database name.
[[ "$slug" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] \
    || { echo "usage: scripts/new-worktree.sh <slug> (letters, digits, '.', '_', '-'; starts with a letter or digit)" >&2; exit 2; }
root="$(cd "$(dirname "$0")/.." && pwd)"
# Always under the main checkout, also when this script runs from a worktree (vendor/ and node_modules come from $root).
main="$(dirname "$(git -C "$root" rev-parse --path-format=absolute --git-common-dir)")"
wt="$main/.claude/worktrees/$slug"
git -C "$root" fetch -q origin develop
git -C "$root" worktree add -q --no-track -b "feature/$slug" "$wt" origin/develop
cd "$wt/billing"
cp -ac "$root/billing/vendor" . 2>/dev/null || cp -a "$root/billing/vendor" .
cp -al "$root/billing/node_modules" . 2>/dev/null || cp -ac "$root/billing/node_modules" . 2>/dev/null || cp -a "$root/billing/node_modules" .
cp "$root/billing/.env" .
touch database/database.sqlite
export XDEBUG_MODE=off
composer dump-autoload -q
php artisan migrate:fresh --seed --no-interaction -q
php artisan wayfinder:generate --with-form > /dev/null
"$wt/scripts/install-hooks.sh" >/dev/null || echo "warning: pre-push gate not installed (run scripts/install-hooks.sh)" >&2
echo "Worktree ready: $wt/billing (branch feature/$slug)"
