#!/usr/bin/env bash
# Create an isolated worktree for a workstream: scripts/new-worktree.sh <module-slug>
# -> /workspaces/whmcs/.worktrees/<slug> on branch feature/<slug> (from a freshly fetched origin/develop, not the
# local develop, which goes stale), dependencies ready.
set -euo pipefail
slug="$1"
root="$(cd "$(dirname "$0")/.." && pwd)"
wt="$root/.worktrees/$slug"
git -C "$root" fetch -q origin develop
git -C "$root" worktree add -q --no-track -b "feature/$slug" "$wt" origin/develop
cd "$wt/billing"
cp -a "$root/billing/vendor" .
cp -al "$root/billing/node_modules" . 2>/dev/null || cp -a "$root/billing/node_modules" .
cp "$root/billing/.env" .
touch database/database.sqlite
export XDEBUG_MODE=off
"$HOME/.local/bin/composer" dump-autoload -q
php artisan migrate:fresh --seed --no-interaction -q
php artisan wayfinder:generate --with-form > /dev/null
echo "Worktree ready: $wt/billing (branch feature/$slug)"
